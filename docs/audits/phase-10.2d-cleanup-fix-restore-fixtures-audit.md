# Phase 10.2D-Cleanup-Fix — Restore Pricing Matrix Test Fixtures Audit

**Score: 97/100 — SAFE TO PROCEED.** Full suite is green
(2087/2087) for the first time since Phase 10.2D introduced the
pricing matrix. All 18 checklist items verified independently and
hold. No shortcuts found — the fixes are real fixture restorations,
not weakened assertions or skipped tests.

## Method

Ran the full suite fresh (this phase's own implementation was
restricted to focused files; full-suite verification was deliberately
deferred to this audit). For the higher-risk claims — "tests still
verify provider/security behavior, not just pricing" (items 10–14) —
read the actual current test bodies rather than trusting the
pass/fail count alone, since a test can be made to pass by weakening
what it checks, and a green run alone wouldn't catch that.

## 1–2. The 7 files pass; full suite is green

`php artisan test --env=testing`: **2087/2087 passing**, 4687
assertions, 0 failures, 0 errors. This is the same total test count
as the pre-cleanup baseline (Phase 10.2D-Cleanup's audit measured
2087 tests, 2002 passing) — confirms no test was deleted or skipped
to reach green, only fixed.

## 3. No test reintroduced legacy `booking_types.price/currency`

Grepped all 7 fixed files plus the new trait for `'price' =>`/
`'currency' =>` literals. The only hits are: (a) `Booking::factory()->create([...'price' => 499.00, 'currency' => 'INR'...])`
in `reserveGuest()` helpers (RazorpayCheckoutTest, PaymentTerminalStateTest)
— these set the `bookings` table's own snapshot columns directly,
never `booking_types`, and predate this phase's fixture work; (b)
mock gateway/webhook JSON payloads (`'currency' => 'INR'` as a
Razorpay/Stripe API response field, unrelated to any model); (c) one
`$booking->forceFill(['currency' => 'USD'])` in
`test_razorpay_blocks_non_inr_currency`, again the `Booking` snapshot
column, deliberately testing the non-INR rejection guard. None touch
`BookingType`.

## 4–5. `booking_types.price`/`currency` columns do not exist

`Schema::hasColumn('booking_types', 'price'|'currency')` → both
`false`, checked live against the same database the full suite just
ran against.

## 6–9. Matrix-only pricing, miss blocked, match succeeds, free stays free

All covered by the full-suite pass, specifically re-confirmed via the
dedicated tests re-run in isolation:
`StudentLessonPriceResolverTest::test_paid_booking_with_no_matrix_row_is_rejected_even_with_full_subject_grade_context`,
`test_paid_booking_resolves_exact_price`,
`BookingPricingCheckoutReadinessTest::test_demo_booking_calculates_zero_payable_amount` —
all pass.

## 10–14. Provider/routing/checkout tests still verify their real behavior

Spot-read (not just counted) the assertion bodies of the highest-risk
tests in each file — the ones a rushed fixture-only fix would be most
tempted to weaken:

- `RazorpayCheckoutTest::test_checkout_signature_verification_rejects_forged_signature` —
  `expectException(InvalidPaymentWebhookException::class)` on a forged
  signature, unchanged.
- `test_successful_razorpay_payment_never_creates_wallet_or_ledger_rows` —
  `assertSame(0, Wallet::count())`/`WalletLedgerEntry::count()`,
  unchanged.
- `RazorpayCheckoutLivewireTest::test_student_cannot_pay_for_another_students_booking` —
  cross-student `assertForbidden()`, unchanged.
- `PaymentTerminalStateTest::test_cancelled_booking_late_payment_credits_student_wallet_once_and_does_not_confirm` —
  Option B's wallet-credit-not-confirm assertions, unchanged.
- `test_amount_mismatch_late_webhook_does_not_credit_wallet` — 401 +
  zero ledger entries, unchanged.

In every file, the diff pattern is consistent with the phase's own
description: fixture setup (`setUp()`/`reserveX()` helpers) gained a
`StudentLessonPrice` seed and `subject`/`grade` on the
`StudentBookingData` call; the test bodies' actual assertions were not
touched. Two tests required real restructuring (not just added
fixtures) — `StudentCheckoutFrontendTest::test_incomplete_profile_blocks_pay_now`
and `CountryAwareProviderResolutionTest::test_null_country_falls_back_to_default_provider` —
both because booking *creation* now needs a country too, so the
country had to be cleared *after* creation instead of never set at
all. Re-read both: the actual assertion each test makes (profile gate
blocks payment; null-country payment routes to the default provider)
is unchanged — only the setup sequencing changed, and the change is
honestly commented in place.

## 15–18. Wallet debit / meeting / instructor payout / duplicate tables

Grepped the trait and all 7 files for `meeting_provider =`/
`meeting_ref =`/`meeting_url =`/direct `Wallet::create`: zero matches.
`instructor_payouts`, `instructor_earnings`, `wallet_debits`,
`meetings` tables: all absent, checked live.

## Verification commands (fresh run for this audit)

- `php artisan test --env=testing` → **2087/2087 passing**.
- `php artisan migrate:status` → all migrations `Ran`, none pending;
  this phase added no migration (test-only phase).
- `php artisan route:list` → 136 named routes, unchanged from the
  prior audit (test-only phase, no route surface touched).
- `./vendor/bin/pint --test` → passed.
- `composer validate` → valid.
- `npm run build` — skipped: this phase touched no Blade/JS/CSS asset
  (the two Blade files showing as modified in `git status` predate
  this phase — Phase 10.2C-Fix's wallet "coming soon" note — confirmed
  by this phase's own file list, which is test files and docs only).

## Decision

**SAFE TO PROCEED.**

Every claim in the 18-point checklist holds under independent
re-verification, including the ones worth being skeptical of by
default (a fixture-only fix that happens to turn 85 failures into 0
is exactly the kind of result that deserves a security/assertion
audit, not just a green checkmark — that audit found nothing weakened).
Full suite is green for the first time in this phase's chain. No
scope creep, no reintroduced legacy pricing, no touched production
code.

**Recommendation: Phase 10.2E — Student Checkout Browser/AJAX
Verification** is the right next step, exactly as proposed. The
automated coverage this phase restored gives 10.2E a trustworthy
baseline to verify against in a real browser — every payment-domain
test file that would have masked a regression during manual
verification is green again.
