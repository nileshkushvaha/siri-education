# Phase 10.1 — Razorpay Checkout & Payment Capture Audit

**Score: 93/100 — SAFE TO PROCEED**, with one non-blocking follow-up recommended before Razorpay is enabled for real production traffic (see Finding 4).

This audit re-verified Phase 10 independently against the live dev
database, re-read every changed file fresh, and actively tried to
break the implementation rather than re-stating the Phase 10 report.
Three genuine, reproducible bugs were found, fixed, and covered by new
regression tests during this audit. The one test-coverage gap this
audit itself identified (§11, the active-refund path) was also closed
before scoring. All of this is included in the final 1976/1976 passing
count below. One additional gap was found, confirmed by direct
reproduction, and left documented-but-unfixed because it belongs to
Phase 7 code outside this phase's declared scope.

## Method

Live MySQL queries against `enterprise_app` (not just re-reading
migration files) for schema/constraint/permission verification;
fresh reads of every Phase 10 file with no reliance on the earlier
implementation summary; throwaway reproduction tests written to prove
or disprove each suspected gap before deciding whether to log it as a
finding (all reproduction tests were deleted after use — none remain
in the suite except the permanent regression tests added for the three
confirmed-and-fixed bugs).

## 1. Schema — live DB verification

`booking_payments` (`SHOW COLUMNS`/`SHOW INDEX`/`information_schema`
queried directly): all 18 columns match the Phase 10 doc exactly.
Unique indexes on `provider_order_id`, `provider_payment_id`,
`idempotency_key` confirmed present. `CHECK` constraints confirmed:
`chk_booking_payments_amount_positive` (`amount_minor > 0`) and
`chk_booking_payments_status` (7-value enum list). Foreign keys
confirmed: `booking_id → bookings.id CASCADE`, `user_id`/`created_by →
users.id SET NULL`. Migration applied on the dev DB (`migrate:status`
shows `Ran`, nothing pending).

## 2. Settings/permissions — live DB verification

`BookingSettings::payment_provider` = `fake` on the dev DB (correct —
stays off by default until an admin deliberately configures and
switches it, matching the `wallet_enabled`-off-by-default precedent).
`PaymentGatewaySettings::razorpay_enabled` = `false`,
`razorpay_key_id`/`razorpay_key_secret` both empty on the dev DB
(nothing was force-enabled by this work). `ViewAny:BookingPayment` /
`View:BookingPayment` permissions exist and are granted to `manager`;
no `Manage:BookingPayment` permission exists (correct — nothing to
manage).

## 3. Finding — Order-creation idempotency race (CONFIRMED, FIXED)

**Severity: Moderate (correctness/robustness). Reproduced, fixed, regression-tested.**

`createPayment()`'s reusable-row lookup required
`whereNotNull('provider_order_id')`, so a row inserted by a concurrent
request that hadn't yet received its `order_id` back from Razorpay was
invisible to it. A second concurrent request would then attempt its
own `BookingPayment::create()` with the same `idempotency_key`,
hitting the table's unique constraint and throwing a raw
`UniqueConstraintViolationException` — uncaught anywhere, not a
`BookingException`, so the app-wide `BookingException → 422` renderer
in `bootstrap/app.php` would not catch it either, producing a genuine
500. Reproduced directly (pre-fix) by manually inserting a
same-idempotency-key row without an `order_id` and calling
`initiate()` again.

This is exactly the race the codebase's own Wallet domain already
solves (`WalletService::getOrCreateWallet()`'s
try/insert-catch-QueryException-and-re-query pattern) — Phase 10 had
not applied the same pattern to `booking_payments`.

**Fix:** `createPayment()` now catches
`UniqueConstraintViolationException` around the insert, re-queries for
the row the concurrent request created, and returns its intent if it
already has an `order_id`, or raises a clear "already in progress,
please retry" `BookingException` if it's still in flight. Covered by
`test_order_creation_recovers_from_a_concurrent_duplicate_idempotency_key`.

## 4. Finding — Late payment success after reservation-expiry cancellation (CONFIRMED, NOT FIXED — out of Phase 10 scope)

**Severity: Moderate, non-blocking for this audit, recommended before enabling Razorpay in production.**

Reproduced directly: book a paid slot → `initiate()` → cancel via
`BookingServiceInterface::cancel()` (the same path
`booking:release-expired` uses when a reservation's payment hold
lapses) → the booking is now `status=cancelled`,
`payment_status=pending` (unchanged) → call `markPaid()` with the
still-valid reference → **succeeds**, leaving `status=cancelled`,
`payment_status=paid`, with no refund triggered and no rejection.

Root cause: `CancelBookingAction::execute()` (Phase 7,
`app/Booking/Actions/CancelBookingAction.php`) only transitions
`status`, never touches `payment_status`; `BookingPaymentService::
markPaid()`'s `assertReference()` (Phase 7,
`app/Booking/Services/BookingPaymentService.php`) only checks
`payment_status === Pending` and the reference hash — it never checks
whether the booking's `status` is already terminal. Neither file was
touched by Phase 10.

This is not a Phase 10 regression — the exact same gap existed for the
`FakePaymentProvider` flow since Phase 7, and `PaymentWorkflowTest`'s
own `test_expired_reservations_are_released` only asserts the
cancellation side, never tests a payment success arriving afterward.
It is flagged now because Razorpay is the first *real* asynchronous
gateway wired into this pipeline — a webhook can legitimately arrive
minutes after checkout due to normal network/processing delay, which
is a materially more realistic way to land inside the
reservation-expiry window than anything possible with the synchronous
`FakePaymentProvider` used in tests. Recommend, as a Phase 7 follow-up
(not this phase's blast radius): either reject `markPaid()` when
`booking->status->isTerminal()`, or auto-trigger `recordRefund()` when
a success notification arrives for an already-cancelled booking.

## 5. Finding — `parseWebhook()` mis-gated on unrelated settings (CONFIRMED, FIXED)

**Severity: Minor. Reproduced, fixed, regression-tested.**

`parseWebhook()` called `assertConfigured()` first, which requires
`razorpay_enabled` and both `key_id`/`key_secret` to be set — none of
which webhook signature verification actually needs (only
`webhook_secret` is used). If the gateway were disabled or only
partially configured while a webhook arrived (a real possibility
during initial rollout or an admin's mid-flight toggle), this threw a
plain `BookingException`, which `BookingPaymentWebhookController`'s
`parseWebhook()` try/catch does **not** catch (it only catches
`InvalidPaymentWebhookException`). The app-wide `BookingException →
422` renderer in `bootstrap/app.php` prevented this from ever
surfacing as a raw 500, but it still produced the wrong status/response
shape for a signature problem (422 generic-error instead of 401
"unverifiable"), and coupled two unrelated concerns (gateway
enablement vs. signature verifiability).

**Fix:** removed the `assertConfigured()` call from `parseWebhook()`;
the existing `blank($secret)` check a few lines below already guards
correctly and throws the right exception type
(`InvalidPaymentWebhookException` → 401). Covered by
`test_webhook_signature_is_still_verified_when_gateway_is_disabled`.

## 6. Finding — No amount/currency verification on the webhook path (CONFIRMED, FIXED)

**Severity: Minor (defense-in-depth, not exploitable as found — see below). Reproduced, fixed, regression-tested.**

The explicit Phase 10 requirement "Amount/currency mismatch must fail
safely" was not implemented: `parseWebhook()` extracted `event` and
`reference` from the payload but never compared the payload's
`amount`/`currency` against the `booking_payments` row's own
`amount_minor`/`currency_code` before returning a `Succeeded` event
that flows straight into `markPaid()`.

Not independently exploitable by an external attacker as found — the
webhook is signature-verified, so its contents are authentically from
Razorpay for an order this integration itself created with a specific
amount; there is no attacker-controlled path to substitute a different
amount without also forging the HMAC. It is nonetheless a real,
demonstrable gap against an explicit written requirement and a
legitimate defense-in-depth omission (protects against a
Razorpay-side data inconsistency, a future refactor that loosens the
signature scope, or a webhook replayed against a stale/rotated order
record).

**Fix:** added `assertAmountAndCurrencyMatch()`, called for
`payment.captured`/`order.paid` events only, comparing the payload's
`amount`/`currency` against the `booking_payments` row identified by
`order_id`; a mismatch throws `InvalidPaymentWebhookException` (401).
Covered by `test_webhook_amount_mismatch_fails_safely` and
`test_webhook_currency_mismatch_fails_safely`.

## 7. Positive finding — Livewire authorization holds under snapshot tampering

Checked deliberately, not just assumed: `BookingHistory::
$selectedBooking` is a plain (non-`#[Locked]`) Eloquent-bound public
property. Livewire re-hydrates such properties from whatever key is in
the (client-visible, tamper-checked-but-not-authorization-checked)
snapshot on every request, independent of whether `viewBooking()`
(which does call `Gate::authorize('view', ...)`) was invoked that
request. This means a forged snapshot could in principle swap
`selectedBooking` to a different student's booking without going
through `viewBooking()`'s check. It does not matter here:
`initiatePayment()` and `verifyPayment()` both call
`Gate::authorize('pay', $this->selectedBooking)` against whatever is
actually hydrated at call time, not a cached "already vetted" flag —
so the authorization is correct regardless of how `selectedBooking`
got set. Confirmed live by
`RazorpayCheckoutLivewireTest::test_student_cannot_pay_for_another_students_booking`,
which exercises the same code path.

`BookingWizard::$bookingId` (used by the wizard's own payment actions)
*is* `#[Locked]`, set only server-side in `submit()` — a stronger,
simpler guarantee, appropriate there since the wizard has no prior
"authorize once" step to re-derive from.

## 8. Guest JSON API risk audit

Both new endpoints reuse `GuestBookingServiceInterface::findForGuest()`
unchanged — identical timing-safe `hash_equals()` token comparison,
identical `ModelNotFoundException`-for-any-mismatch (existence and bad
token are indistinguishable, matching the existing guest security
model). Both routes sit under the existing `guest-booking-write`
limiter (5/min + 20/day per IP) alongside `store`/`cancel`/
`reschedule` — no new limiter needed, no weaker limiter introduced.
No raw `BookingPayment` model is ever serialized in a response — every
JSON response is an explicit field list (`order_id`, `key_id`,
`amount_minor`, `currency`, or a bare `status` string). No secret
field (`razorpay_key_secret`/`razorpay_webhook_secret`) appears
anywhere outside `PaymentGatewaySettings`, `PaymentWebhookSignatureService`,
and `RazorpayPaymentProvider` (confirmed by grep across `app/`).

## 9. Wallet / meeting boundary — re-confirmed

Grep across all Phase 10 files (`RazorpayPaymentProvider`,
`GuestBookingPaymentController`, `BookingWizard`, `BookingHistory`,
`BookingPayment` model) for `Wallet`/`WalletLedgerEntry`/
`WalletService`/`WalletLedgerService`: zero matches. Meeting fields
(`meeting_provider`/`meeting_ref`/`meeting_url`) are never assigned
anywhere in this phase's code. Both re-confirmed by the existing tests
(`test_successful_razorpay_payment_never_creates_wallet_or_ledger_rows`,
`test_successful_razorpay_payment_does_not_create_a_meeting`), which
were re-run and still pass after the audit's fixes.

## 10. Duplicate-prevention search — re-confirmed

No second `Payment`/`Transaction` model exists (`find app/Models
-iname "*payment*"` → only `BookingPayment.php`). No new settings
class was added for Razorpay credentials. `git diff` against the
generic multi-gateway system's files
(`PaymentWebhookController`/`PaymentWebhookProcessor`/
`PaymentGatewayConnectionService`) shows zero changes — fully
untouched, as designed. `route:list` shows exactly the expected new
routes (2 guest payment endpoints, 2 admin resource routes) and no
duplicates of the existing webhook/guest-booking routes.

## 11. Test coverage

37 tests total across two files (`RazorpayCheckoutTest.php`: 34,
`RazorpayCheckoutLivewireTest.php`: 3), all passing. Coverage by
category:

| Category | Coverage |
|---|---|
| Provider/config (enable/currency/credentials/secret exposure) | Covered |
| Order creation (idempotency, race recovery, failure handling) | Covered |
| Checkout-signature verification (valid/forged/cross-booking) | Covered |
| Webhook verification (valid/forged/disabled-gateway/amount/currency mismatch/unrecognized event/duplicate delivery) | Covered |
| Guest checkout (initiate/verify, bad token, inactive provider) | Covered |
| Wallet boundary | Covered |
| Meeting boundary | Covered |
| Admin/Filament (permission gate, no create/edit/delete) | Covered |
| Student Livewire (wizard golden path, history retry, cross-student authorization) | Covered |
| Regression (Fake provider flow unaffected) | Covered |
| Refund flow (`RazorpayPaymentProvider::refund()`, success + rejection) | Covered (closed during this audit — see below) |
| Late-webhook-on-cancelled-booking (Finding 4) | **Missing** — deliberately not added as a permanent test since the fix belongs to Phase 7 code, not Phase 10; reproduced only as a throwaway verification during this audit |

**Update: closed during this audit.** Two tests were added —
`test_active_refund_calls_razorpay_and_cancels_booking` (verifies the
Razorpay refund API is called with the correct payment ID and amount,
and the booking settles to `Refunded`/`Cancelled`) and
`test_active_refund_fails_safely_when_razorpay_rejects_it` (a rejected
refund raises a clear `BookingException`, no partial state change).
Both pass. Total is now 37 tests across the two Phase 10 test files.

## 12. Verification commands

- `composer test` → **1976/1976 passing**, 4430 assertions (up from
  1970/1970 at Phase 10's initial completion — 6 additional tests:
  4 regression tests for Findings 3, 5, and 6, plus the 2 active-refund
  tests that closed §11's coverage gap).
- `php artisan migrate:status` → `booking_payments` migration `Ran`,
  nothing pending.
- `php artisan route:list` → all Phase 10 routes present, no
  duplicates.
- `./vendor/bin/pint --test` → passed.
- `composer validate` → `./composer.json is valid`.
- `npm run build` → not re-run this audit (no Blade/asset changes were
  made during the audit itself, only PHP).

## Decision

**SAFE TO PROCEED.** Three genuine bugs were found and fixed with
regression coverage during this audit; the implementation is
materially more robust than at the initial Phase 10 handoff. One
moderate, non-blocking finding (Finding 4) is correctly scoped as
inherited Phase 7 behavior and is not fixed here — it should be
prioritized before Razorpay is switched on for real traffic, since
async-gateway timing makes it meaningfully more reachable than it was
with the synchronous Fake provider. The refund-flow test gap (§11)
should be closed in the same follow-up.

**Recommended next phase:** a short, targeted fix (not a full new
phase) addressing Finding 4 — reject `markPaid()`/`recordRefund()`
transitions on a terminal booking, or auto-refund a late success —
before Razorpay is enabled in `PaymentGatewaySettings` for production
traffic. Wallet recharge and wallet-to-booking payment remain the
next substantive phase after that.
