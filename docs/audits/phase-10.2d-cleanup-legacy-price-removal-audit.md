# Phase 10.2D-Cleanup — Legacy BookingType Price/Currency Removal Audit

**Score: 71/100 — PROCEED WITH CAUTION.** The cleanup itself is
complete and correct — every claim in the 14-point checklist holds
under fresh re-verification. But a full-suite run (deliberately
withheld during implementation, run for the first time in this audit)
confirms real, quantified fallout: **85 tests across 7 files** now
fail, all with the exact same, expected root cause. This was
disclosed in advance by the implementation itself, not discovered
here — but "disclosed" is not the same as "fixed," and a red CI run
is a red CI run regardless of whether it was predicted.

## Method

Re-read every changed file fresh rather than trusting the prior
session's own summary of what it changed. Ran the full suite for the
first time (implementation was explicitly restricted to 4 focused
filters) specifically to quantify the disclosed-but-unmeasured fallout
rather than take the "10 files, deliberate tradeoff" claim on faith.

## Full-suite result — the fallout, quantified

`php artisan test --env=testing`: **2002/2087 passing**, 84 errors + 1
failure = **85 broken tests**, all in `tests/Feature/Booking/`:

| File | Broken |
|---|---|
| `RazorpayCheckoutTest` | 27 |
| `StripeCheckoutTest` | 19 |
| `StudentCheckoutFrontendTest` | 12 |
| `PaymentWorkflowTest` | 10 |
| `PaymentTerminalStateTest` | 8 |
| `CountryAwareProviderResolutionTest` | 6 |
| `RazorpayCheckoutLivewireTest` | 3 |

Every single one fails with the identical, expected message: *"The
'[fake type name]' lesson price is not configured yet. Please contact
support."* — i.e., these are not crashes, not data corruption, not
security regressions. They are `BookingType::factory()->paid()`
fixtures with no matching `StudentLessonPrice` row, hitting exactly
the guard this cleanup was built to enforce. This is the calculator
working as designed against fixtures that predate the design.

**Correction to the implementation's own risk disclosure:** the
cleanup's doc listed 10 files as "known broken." Three of them —
`BookingAnalyticsTest`, `Guest/GuestBookingTest`,
`Student/PaymentHistoryTest` — are **not** actually broken, verified
by running them and by reading why: `BookingAnalyticsTest` builds
`Booking` rows directly via `Booking::factory()->create(...)`, never
calling `BookingService::request()`, so it never reaches
`BookingPriceCalculator` at all. `PaymentHistoryTest` uses
`Booking::factory()->paid()` (sets `bookings.price`/`currency` — the
snapshot columns, untouched by this cleanup — directly), not
`BookingType::factory()->paid()` in the one place that matters.
`Guest/GuestBookingTest` attempts a guest booking, which
`AuthenticatedAttendeeRule` rejects before the pricing calculator ever
runs (guest denial fires earlier in the pipeline than pricing). None
of these three needed fixing and the original "10 files" estimate was
conservative-but-imprecise — a minor inaccuracy in the prior report,
corrected here, not a functional gap.

## 1–2. `booking_types.price`/`currency` not used anywhere — confirmed

Fresh grep across `app/`: zero code references, only two doc-comment
mentions of the *historical* fact that they used to exist (in
`BookingPriceCalculator.php` and `StudentLessonPriceResolver.php`).
Schema check via `Schema::hasColumn('booking_types', 'price'|'currency')`
→ both `false`.

**Finding (fixed during this audit):** `StudentLessonPriceResolver`'s
class docblock was stale — it still described `BookingPriceCalculator`
as "the one place allowed to catch [a resolver miss] and fall back to
the deprecated `booking_types.price`/`currency` pair," which was true
during Phase 10.2D but became false the moment this cleanup shipped
(the calculator no longer catches anything or falls back to anything).
A future engineer reading only this file would be told a fallback
exists that doesn't. Corrected in place — a one-comment fix, no
behavior change.

## 3. BookingType admin form — confirmed clean

Fresh read of `BookingTypeForm.php`: no `price`/`currency` field
exists. `BookingAdminPanelTest::test_booking_type_form_no_longer_exposes_price_or_currency_fields`
(`assertFormFieldDoesNotExist`) re-run in isolation, passes.

## 4–5. Paid price comes only from the matrix; missing match blocks booking — confirmed

`BookingPriceCalculator::calculate()` re-read fresh: `resolveFromMatrix()`
throws in all four miss conditions (no subject/grade, no billing
country, no `Subject` match, resolver miss) — there is no code path
back to a decimal column. The 85-test fallout above is itself the
strongest possible evidence this holds in practice, not just in
theory.

## 6. Demo/free booking remains free — confirmed

`test_demo_booking_calculates_zero_payable_amount` and
`test_free_demo_booking_auto_confirms_and_requires_no_payment`
re-run, pass.

## 7. Booking snapshots preserve resolved amount/currency — confirmed

`Schema::hasColumn('bookings', 'price'|'currency')` → both `true`.
`test_booking_snapshot_uses_resolved_matrix_amount_and_currency`
re-run, passes.

## 8. Admin can manage StudentLessonPrice — confirmed

`StudentLessonPriceAdminTest` (7 tests, including the new
duplicate-active-row guard) re-run in isolation, all pass.

## 9. Student can see payable price — confirmed

`StudentBookingTest::test_student_books_paid_session_with_chosen_teacher`
(`data.price === '49.99'`) re-run, passes.

## 10. Instructor cannot see student price or pricing matrix — confirmed

`test_instructor_cannot_access_the_pricing_admin_at_all` re-run,
passes — re-verified this still relies on `User::canAccessPanel()`
gating the whole admin panel (unchanged by this cleanup), not on
`StudentLessonPricePolicy` alone.

## 11–14. Wallet debit / meeting creation / instructor payout / duplicate tables — confirmed absent

`test_no_instructor_payout_wallet_debit_meeting_or_duplicate_tables_were_introduced`
re-run, passes. Fresh grep for `meeting_provider =`/`meeting_ref =`/
`meeting_url =`/direct `Wallet::`/`WalletLedgerEntry::create` writes
across every file this cleanup touched: zero matches. `find app/Models
-iname "*payment*"` still shows only `BookingPayment.php`; no
`instructor_payout`/`instructor_earnings` table or model exists.

## Verification commands (fresh run for this audit)

- `php artisan test --env=testing` → **2002/2087 passing**, 85 broken
  (all disclosed root cause, quantified above).
- `php artisan migrate:status` → both new migrations
  (`create_student_lesson_prices_table`,
  `drop_price_and_currency_from_booking_types_table`) show `Ran`,
  nothing pending.
- `php artisan route:list` → `admin/booking-types` (3 routes) and
  `admin/student-lesson-prices` (3 routes) both present and correctly
  scoped; 136 named routes total, no unexpected additions/removals.
- `./vendor/bin/pint --test` → passed.
- `composer validate` → valid.
- `npm run build` — **skipped**: this cleanup phase touched no
  Blade/JS/CSS asset (confirmed by direct review of the phase's own
  file list — only PHP, migrations, seeders, and docs changed).

## Decision

**PROCEED WITH CAUTION.**

The cleanup itself is done correctly and completely — all 14 requested
checks hold, one stale doc-comment was found and fixed, and the
implementation's own risk disclosure was accurate in kind (broken
tests, single root cause) if slightly imprecise in count (7 files, not
10 — corrected above). Nothing unexpected, unsafe, or silently broken
was found.

It cannot be called unqualified-safe while 85 tests are red across a
meaningful slice of the payment domain (all of Razorpay, Stripe,
country-routing, terminal-state, and student-checkout-frontend
coverage). That is a real, current gap in this codebase's safety net,
even though the underlying application code is correct.

**Recommendation: neither named option yet.** Both Phase 10.2E
(Student Checkout Browser/AJAX Verification) and Phase 11 (Meeting
Creation Foundation) build directly on top of the checkout flow whose
automated coverage is currently red. Recommend a short, mechanical
**Phase 10.2D-Cleanup-Fix** first: give each of the 7 broken files a
`StudentLessonPrice` fixture (the same pattern already proven in
`BookingPricingCheckoutReadinessTest`/`StudentBookingTest` this
session — a shared subject/country/currency + `seedLessonPrice()`
helper), restoring 2087/2087. After that, **Phase 10.2E** is the
better of the two follow-ups to do first — it directly re-validates
the checkout flow whose test coverage will have just been restored,
before Phase 11 adds a new feature (meeting creation) on top of it.
