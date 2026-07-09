# Phase 10.2C-Fix — Authenticated-Only Booking + Paid Pricing Guard + Payment Method Visibility Audit

**Score: 62/100 — PROCEED WITH CAUTION.** Every claim made for Phase
10.2C-Fix itself checks out under re-verification. One **critical,
live-proven, pre-existing** vulnerability was found while tracing every
caller of `BookingPaymentService`, sitting directly in the payment
trust boundary this phase extends — it is not something Phase 10.2C-Fix
introduced, but it materially undermines "profile completion guard"
and "ownership authorization" for the payment system as a whole, and
must be closed before this can be called safe end-to-end.

## Method

Fresh re-run of every verification command (not trusting the prior
report), fresh re-read of the actual `GLOBAL_RULES` wiring, and a
deliberate attempt to find every production caller of
`BookingPaymentService::initiate()`/`markPaid()` rather than trusting
that the two audited Livewire components are the only ones. That last
step is what surfaced the critical finding below — proven live with a
throwaway Feature test (run, captured, then deleted; not part of the
committed suite).

## Critical finding: `StudentBookingController::pay()` accepts client-forged payment confirmation

`app/Http/Controllers/Student/StudentBookingController.php` is a
JSON API (`dashboard.bookings.*`, session-auth, `auth` +
`EnsureAccountIsActive` middleware) that has existed since before this
phase and is referenced by **zero** Blade views, zero JS, and zero
tests anywhere in the repo (`grep -rl StudentBookingController
tests/ resources/` returns nothing) — it was flagged in the prior
session's notes as a finding but incorrectly characterized as
"unreachable." It is not unreachable: it is live on the route table
and fully authenticated-callable by any active student today.

Two of its methods bypass every guarantee this phase built:

1. **`store()`** creates a booking and, via `paymentIntentFor()`, calls
   `BookingPaymentService::initiate()` directly — the same service
   method the Livewire components call, but **without** the
   billing-country/profile-completeness check this phase added (that
   check lives only in `BookingWizard::initiatePayment()` and
   `BookingHistory::initiatePayment()`, not in the service). A student
   with no `country_id` on their profile can get a real payment order
   created through this endpoint today.
2. **`pay(Request $request, Booking $booking)`** calls
   `Gate::authorize('pay', $booking)` (ownership-only — correctly
   implemented, confirmed by reading `BookingPolicy::pay()`) and then
   calls `BookingPaymentService::markPaid($booking, $request->string('reference'))`
   with **the raw client-submitted string, with no gateway signature
   verification of any kind.** `markPaid()`'s only check
   (`assertReference()`) is a string comparison against
   `booking->payment_reference` — a value the client is *handed back*
   in `store()`'s own JSON response (`payment.reference`). Compare
   this to the two legitimate Livewire paths, both re-verified in this
   audit: `BookingHistory::verifyPayment()` always calls
   `RazorpayPaymentProvider::verifyCheckout()` (real HMAC signature
   check) before ever calling `markPaid()`, and
   `simulateFakePayment()` independently re-checks
   `app()->environment(['local', 'testing'])` inside the method
   itself. `StudentBookingController::pay()` has neither guard.

**Live proof (throwaway test, run once, then deleted — not committed):**
an authenticated student with no billing country set, against the
default `fake` (unconfigured) payment provider, was able to: create a
₹499 paid booking via `POST /dashboard/bookings`; receive
`payment.reference = "PAY-58XMFCXEA3JY"` in that same response; then
call `POST /dashboard/bookings/{id}/pay` with `{"reference":
"PAY-58XMFCXEA3JY"}` and zero Razorpay/Stripe interaction. Result:
`payment_status` → `paid`, `status` → `confirmed`. No money moved, no
gateway was ever contacted. The booking's numeric `id` — not exposed
by `StudentBookingResource` itself — is trivially available anyway,
since the real dashboard renders `wire:click="viewBooking('{{
$booking->id }}')"` directly into the page HTML the same student
legitimately loads (`booking-history.blade.php:16`).

**Why this belongs in this audit, not just a footnote:** the user
explicitly asked to strictly audit "profile completion guard" and
"ownership authorization." Both claims are true *for the two Livewire
entry points this phase built*, and false *in general*, because a
third, older, dead-to-the-UI-but-live-on-the-router entry point shares
the same service layer and enforces neither. "No guest booking" is
also relevant context: this endpoint requires authentication (so it is
not a guest-booking gap), but it proves that authentication alone was
never the missing control — payment authenticity was, and it is still
missing on this one path.

**Recommended fix (not applied — outside this phase's declared scope,
flagged for a decision):** the same treatment already given to the
guest payment routes this phase — remove `pay()` (and ideally
`store()`'s `paymentIntentFor()` auto-initiate) from `routes/web.php`,
since the route is provably unused by the real product and its
existence is the entire vulnerability. A more invasive alternative
(add real gateway verification to `pay()`) is possible but is
building out a second checkout entry point that would then need its
own frontend, tests, and country/pricing gating — disabling is the
smaller, safer change consistent with "make the smallest possible
change."

## 1. No guest booking/payment access — re-verified, holds

- `AuthenticatedAttendeeRule` confirmed first in `GLOBAL_RULES` (fresh
  read of `BookingService.php:55-70`), throws before any other rule
  runs.
- `routes/api.php` re-listed live: `payments/razorpay/initiate` and
  `.../verify` are absent from the route table
  (`php artisan route:list --path=guest` → 8 routes, matches the
  documented set exactly, no payment routes present).
- `routes/web.php`: `/book` and `/instructors/book` both carry `auth`
  in their middleware array (`route:list --path=book` confirms both
  routes exist and the full suite's redirect tests for both pass).
- Full suite includes explicit denial tests for demo and paid guest
  booking attempts, forged/valid captcha, honeypot, and both payment
  routes returning 404 — all re-run fresh, all green.

## 2. Authenticated student-only booking — re-verified, holds

`VerifiedActiveStudentRule` re-read fresh: rejects non-active status
and (when `AuthenticationSettings::email_verification_required`)
unverified email, second in `GLOBAL_RULES`. `StudentEligibilityRuleTest`
(6 tests: active/inactive/suspended/unverified-with-and-without-the-setting/
free-booking-without-country) re-run in isolation, all pass.

## 3. Profile completion guard — holds for its two built entry points, does not hold generally

Confirmed exactly as designed for `BookingWizard`/`BookingHistory`
(`test_incomplete_profile_blocks_pay_now` re-run, passes). **Does not
hold** for `StudentBookingController` — see critical finding above.
This is the single biggest gap between "the claim" and "the system."

## 4. Paid booking type NULL price/currency rejection — re-verified, holds without gaps

`BookingPriceCalculator::calculate()` throws at booking-creation time
(inside `BookingService::request()`/`StudentBookingService::book()`),
setting `booking.price`/`booking.currency` on the row itself — every
later payment-path reader (including the vulnerable
`StudentBookingController`) reads that already-validated column, not
the `BookingType` again, so this specific guard has no bypass through
any caller, including the one above. Admin form (`price
minValue(0.01)`, both fields `requiredIfAccepted('is_paid')`)
re-confirmed by re-reading `BookingTypeForm.php`. 15/15
`BookingPricingCheckoutReadinessTest` + 8/8
`BookingAdminPanelTest` re-run, pass.

## 5. Payment method visibility — holds for the built UI, N/A (not a bypass) for the JSON API

Wallet "coming soon" note and provider-gated fake/Stripe branches
re-confirmed present in both Blade files. `StudentBookingController`
has no view layer, so "visibility" doesn't apply to it directly — its
issue is purely the authenticity gap in §Critical finding, not a
missing UI element.

## 6. Razorpay/fake/Stripe frontend states — re-verified, holds

`test_fake_payment_success_works_in_testing_and_confirms_booking`,
`test_simulate_fake_payment_is_a_no_op_outside_local_or_testing`, and
`test_stripe_selected_shows_safe_coming_soon_message_and_leaks_no_secret`
re-run in isolation — all pass. `simulateFakePayment()` re-read: the
`app()->environment(['local', 'testing'])` check is inside the method
body, not just Blade visibility, so it cannot be invoked in production
by any means available to it (it is a Livewire public method, callable
directly, which is exactly why the in-method check — not just the
Blade `@if` — is the real boundary; confirmed this is where the actual
safety property lives, not assumed).

## 7. Ownership authorization — the policy itself is correct; the gap is elsewhere

`BookingPolicy::pay()` re-read fresh: `$user->id === $booking->attendee_id
|| hasPermission('Update:Booking')` — correctly blocks cross-student
payment attempts. `test_another_student_cannot_view_or_pay_for_this_booking`
and `test_student_cannot_pay_for_another_students_booking` re-run,
pass. The critical finding is not an ownership-authorization bug —
it's a **payment-authenticity** bug that ownership authorization alone
was never able to cover (the legitimate owner is exactly the actor
with incentive to forge a payment for their own booking).

## 8. Random Razorpay credential safety — re-verified, holds

`PaymentProviderConfigValidator::isValidRazorpayKeyId()` re-read:
regex-anchored to `rzp_(test|live)_...`, unchanged this phase.
`test_random_razorpay_credentials_still_fail_safely_with_country_routing`
re-run, passes.

## 9. No wallet debit — re-verified, holds

Grep across every file this phase touched plus
`BookingPaymentService.php`/both Livewire components for direct
`Wallet::`/`WalletLedgerEntry::create` writes outside
`WalletService`/`WalletLedgerService`: zero matches. The only wallet
credit path remains Option B's late-terminal-payment credit
(Phase 10.2B, unchanged, tested).

## 10. No meeting creation — re-verified, holds

Grep for `meeting_provider =`/`meeting_ref =`/`meeting_url =` across
`BookingPaymentService.php`, both Livewire components, and
`StudentBookingController.php`: zero matches.

## 11. No duplicate structures — re-verified, holds

`find app/Models -iname "*payment*"` → `BookingPayment.php` only.
`migrate:status` → 0 pending, this phase added none (confirmed via
`git status` showing no new migration file for this phase's own diff).
`test_no_duplicate_payment_wallet_or_pricing_tables_exist` re-run,
passes.

## Verification commands (fresh run for this audit)

- `php artisan test --env=testing` → **2067/2067 passing**, 4638
  assertions.
- `php artisan migrate:status` → all ran, none pending.
- `php artisan route:list` → guest payment routes absent; `/book`,
  `/instructors/book` present and auth-gated; `dashboard/bookings/{booking}/pay`
  confirmed present and is the subject of the critical finding above.
- `./vendor/bin/pint --test` → passed.
- `composer validate` → valid.
- `composer show razorpay/razorpay stripe/stripe-php` → 2.9.3 /
  v20.3.0, unchanged.
- `npm run build` → built in <1.2s, no errors.

## Decision

**PROCEED WITH CAUTION.**

Every claim specific to Phase 10.2C-Fix's own deliverables — no guest
booking, authenticated-only booking, the paid-pricing guard, payment
method visibility, provider frontend states, ownership authorization
as a policy, credential-format safety, and the wallet/meeting/
duplicate-structure boundaries — was independently re-verified this
session and holds with no gaps found.

The one critical finding (`StudentBookingController::pay()` accepting
unverified client-submitted payment confirmation, and `store()`
bypassing the profile-completeness gate) is **not** part of Phase
10.2C-Fix's own changes, but it is live, reachable by any authenticated
student today, and was proven exploitable end-to-end in this audit. It
must be closed — recommend removing `pay()`/auto-`initiate()` from
`routes/web.php` (same treatment already given to guest payment routes
this phase) — before this payment system as a whole can be marked safe
for real Razorpay/Stripe traffic. This was not fixed during the audit
itself, since it sits outside Phase 10.2C-Fix's declared scope and the
fix is a decision worth confirming rather than making unilaterally.
