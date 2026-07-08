# Phase 10 Razorpay Checkout & Payment Capture Foundation

## Decision

Phase 10.0 audited the codebase before writing anything, per the
"reuse existing Services/Repositories/Policies/Settings before creating
new ones" rule. That audit found two things that shaped every decision
below: the Booking module already has a complete, tested, provider-
agnostic payment pipeline (`PaymentProviderInterface` →
`PaymentProviderRegistry` → `BookingPaymentService` →
`BookingPaymentWebhookController`) built across Phases 7–9 with only a
`FakePaymentProvider` implementation; and a separate, unrelated,
already-built admin module (`PaymentGatewaySettings`,
`PaymentSettingsPage`, `PaymentWebhookController`) already has
encrypted Razorpay credential fields (`razorpay_key_id`,
`razorpay_key_secret`, `razorpay_webhook_secret`, `razorpay_enabled`,
`razorpay_sandbox_mode`) with a full admin UI, but zero booking
integration and zero data persistence (no `Payment` model, no table).

Phase 10 is `RazorpayPaymentProvider implements PaymentProviderInterface`
— one class, registered in the existing registry, selected via the
existing `BookingSettings::payment_provider` setting, reusing the
existing `PaymentGatewaySettings` credential storage. This is exactly
the extension point `docs/booking.md` already documented: "Adding
Stripe/Razorpay = one class + one registry line + a settings change."
The generic multi-gateway system (`PaymentWebhookController`,
`PaymentWebhookProcessor`, `PaymentGatewayConnectionService`,
`BankSettings`, `PaymentConfigurationSettings`, `PaymentAdvancedSettings`)
is untouched — it is a separate, broader (Stripe/PayPal/Cashfree/PayU/
PhonePe/manual/invoicing) concern outside this phase's Razorpay-only,
booking-checkout scope, and the user's "Razorpay only... do not add
other gateways" instruction rules out expanding it here.

## Prerequisite

`docs/audits/phase-9-wallet-ledger-foundation-audit.md`: 94/100, SAFE
TO PROCEED. All 4 minor findings were resolved before this phase began
(see that doc's "Phase 9.1 audit findings — resolved in Phase 10"
section): `WalletLedgerService::reverse()` docblock clarified,
`WalletOverview`'s single-currency assumption documented, a debit
idempotency-key test added, and `adjustment()`'s strict-int-typing test
extended to cover the new method.

## Existing Payment State (Phase 10.0 audit)

- `PaymentProviderInterface` (`key()`, `createPayment()`, `refund()`,
  `parseWebhook()`), `PaymentProviderRegistry`, `FakePaymentProvider`,
  `PaymentIntentData`, `PaymentWebhookData`, `PaymentWebhookEvent`
  (`Succeeded`/`Failed`/`Refunded`) — all Phase 7 scaffolding, fully
  wired into `BookingPaymentService` and `BookingPaymentWebhookController`,
  fully tested (`PaymentWorkflowTest`, 10 tests). Untouched except for
  one additive enum case (`PaymentWebhookEvent::Ignored`, see below).
- `BookingPaymentService`: `initiate()` / `markPaid()` / `markFailed()`
  / `refund()` / `recordRefund()`. `markPaid()`'s `assertReference()`
  does `hash_equals($booking->payment_reference, $reference)` — this
  remains the only safe paid transition; Razorpay's new controllers and
  Livewire actions all call it, never duplicate its logic.
- `bookings` table already carries a lightweight payment snapshot:
  `price` (`decimal:2` — a documented pre-existing float-precision
  design note, not changed here), `currency` (char 3), `payment_status`,
  `payment_reference`, `reserved_until`. No `provider`/`provider_order_id`
  /`provider_payment_id`/`payment_method`/`idempotency_key`/`metadata`
  fields existed — this is the gap `booking_payments` fills.
- `PaymentGatewaySettings` (group `payment_gateways`): already has
  `razorpay_enabled`, `razorpay_sandbox_mode`, `razorpay_key_id`,
  `razorpay_key_secret` (encrypted via `Crypt::encryptString` in
  `PaymentSettingsPage::saveEncryptedField()`, never prefilled back into
  the form), `razorpay_webhook_secret` (same). Reused as-is — no new
  settings class for credentials.
- `PaymentWebhookSignatureService::decryptSecret()` was `private`;
  changed to `public static` (one-line visibility change) so
  `RazorpayPaymentProvider` can reuse the same decrypt-with-legacy-
  fallback routine instead of duplicating it.
- No `Payment`/`Transaction` model or table existed anywhere. No
  Razorpay SDK dependency existed or was added — order/refund calls use
  Laravel's `Http` facade directly (same approach
  `PaymentGatewayConnectionService::testRazorpay()` already used for its
  connectivity check), avoiding a new Composer dependency for what is
  two simple REST calls.
- `BookingPolicy::pay()` already existed (`$user->id ===
  $booking->attendee_id || hasPermission('Update:Booking')`) —
  forward-looking scaffolding from an earlier phase, now used by
  `BookingHistory`'s retry-payment action.
- Guest booking token pattern (`manage_token`, SHA-256 hashed,
  `GuestBookingServiceInterface::findForGuest()`) reused as-is for the
  new guest payment endpoints — no new credential.

## Gateway: Razorpay Only, INR Primary

`RazorpayPaymentProvider` (`app/Booking/Payments/RazorpayPaymentProvider.php`)
hard-codes `SUPPORTED_CURRENCY = 'INR'`. `createPayment()` rejects any
booking whose `currency` is not `INR` with a clear `BookingException`
before an order is ever created — no silent conversion, no partial
support. Amounts are always integer minor units (paise): `toMinorUnits()`
reads `Currency::minor_units` for INR (2) and does
`(int) round($amount * 10 ** $minorUnits)`; `booking_payments.amount_minor`
is `unsignedBigInteger`, never a float, matching the wallet ledger's
existing int-minor-units discipline.

Secrets: `razorpay_key_id` is not secret (Razorpay's own client-side
SDK embeds it) and is exposed to the frontend via `checkoutPayload()`.
`razorpay_key_secret` and `razorpay_webhook_secret` are decrypted only
inside `RazorpayPaymentProvider` at the moment of use, never logged,
never serialized into an API resource, activity log, or Livewire
property. `assertConfigured()` guards every gateway call: Razorpay must
be both `razorpay_enabled` in `PaymentGatewaySettings` AND selected via
`BookingSettings::payment_provider = 'razorpay'` before any order can
be created — two independent switches, matching the "feature stays off
until consciously turned on" pattern already used for
`FeatureSettings::wallet_enabled`.

## `booking_payments` Table

One row per gateway payment **attempt**, not one row per booking — a
retried/failed payment creates a new row rather than mutating the old
one, preserving full attempt history for support. Not the wallet
ledger: this tracks a booking's gateway order/payment lifecycle only;
it never records money movement between wallets (see Wallet Boundary
below).

Columns: `id` (uuid), `booking_id` (FK, cascade), `user_id` (nullable
FK, null for guest), `provider`, `provider_order_id` (unique,
nullable), `provider_payment_id` (unique, nullable),
`provider_signature` (nullable, **always left null** — see below),
`amount_minor` (unsigned bigint), `currency_code`, `status`
(`pending`/`authorized`/`captured`/`failed`/`cancelled`/`expired`/
`refunded`, DB `CHECK`-constrained), `payment_method` (nullable, e.g.
`upi`), `idempotency_key` (unique, nullable — the booking's
`payment_reference`), `metadata` (json, receipt only — never card/UPI
details), `paid_at`, `failed_at`, `created_by`, timestamps. A DB
`CHECK` enforces `amount_minor > 0`.

`provider_signature` is never populated: the signature is a one-time
verification token with no legitimate use once consumed, and the row's
`status`/`paid_at` already prove verification happened. The column
exists (matching the requested field list) but the code path never
writes to it.

`BookingPayment` model uses `LogsActivity` directly (`logOnly(['status',
'provider', 'amount_minor', 'currency_code'])`) — the same pattern
`Wallet` and `Booking` already use, not a new convention.

## Order Creation

`RazorpayPaymentProvider::createPayment()` (called by the existing
`BookingPaymentService::initiate()`, never directly):

1. Asserts Razorpay is enabled and configured.
2. Rejects non-INR currency.
3. Idempotency: looks for an existing `booking_payments` row with the
   same `booking_id` + `idempotency_key` (= the booking's
   `payment_reference`) in `Pending` status with a `provider_order_id`
   already set — if found, reuses it instead of calling Razorpay again
   (covers double-click / page-refresh; verified by
   `test_order_creation_is_idempotent_on_repeated_initiate`, which
   asserts exactly one HTTP call).
4. Creates a `booking_payments` row (`status = pending`), calls
   Razorpay's Orders API (`POST /v1/orders`, basic-auth key_id:key_secret,
   `receipt` = the booking's payment reference, `notes.booking_reference`
   = the booking reference), then stores the returned `order_id`. A
   failed API call marks the row `failed` and raises `BookingException`
   — nothing else changes.

No meeting link, no wallet debit, and no auto-mark-paid ever happen at
order-creation time — verified explicitly by
`test_order_creation_does_not_mark_booking_paid_or_create_meeting`.

## Payment Verification — Two Independent, Server-Side Paths

Both paths are mandatory; neither trusts the client alone; both
converge on the same `booking_payments` row and the same
`BookingPaymentService::markPaid()`.

**1. Checkout callback** (`RazorpayPaymentProvider::verifyCheckout()`) —
the fast path. Razorpay's Checkout.js `handler` callback returns
`razorpay_order_id`, `razorpay_payment_id`, `razorpay_signature`
client-side; the signature is `HMAC_SHA256("{order_id}|{payment_id}",
key_secret)`, verified server-side with `hash_equals()`. A forged
signature throws `InvalidPaymentWebhookException` (→ 401). The method
also confirms the order belongs to the booking being paid — a stolen/
replayed order+payment+signature triple for a *different* booking
cannot settle this one (`test_checkout_verification_rejects_order_from_a_different_booking`).
If the row is already terminal, this is a no-op (idempotent).

**2. Webhook** (`RazorpayPaymentProvider::parseWebhook()`) — the
authoritative fallback, since a client can close the tab before the
callback fires. Verifies `X-Razorpay-Signature` against
`HMAC_SHA256(raw_body, webhook_secret)`. Maps `payment.captured` /
`order.paid` → `Succeeded`, `payment.failed` → `Failed`,
`refund.created` / `refund.processed` → `Refunded`, anything else
(e.g. `payment.authorized`) → the new `PaymentWebhookEvent::Ignored`
case, which `BookingPaymentWebhookController` acknowledges with
`{"status":"ignored"}` (200) rather than rejecting with 401 — a
one-line addition to that controller's existing match expression, so
Razorpay stops retrying an event this integration deliberately doesn't
act on. Routes through the **existing**
`/api/webhooks/bookings/payments/{provider}` endpoint with `provider =
razorpay` — no new webhook route was needed.

Both paths reload the booking immediately before calling `markPaid()`
(`$booking->refresh()`), matching the same discipline the pre-existing
webhook controller already relies on (`assertReference()` only guards
against a stale in-memory read, not a stale one) — a concurrent
webhook + checkout-callback race resolves cleanly: whichever arrives
first settles the booking; the second sees a non-payable status and
no-ops (`test_webhook_duplicate_delivery_is_idempotent`).

Amount/currency mismatch and unknown-reference webhooks fail safely:
an unrecognized `payment_reference` returns `{"status":"ignored",
"reason":"unknown reference"}` (pre-existing controller behavior,
unchanged) rather than processing a guess.

## Wallet Boundary — Option A (per explicit instruction)

Razorpay payment marks the booking paid **directly**; the wallet
ledger is never touched by a booking payment. `RazorpayPaymentProvider`,
`GuestBookingPaymentController`, `BookingWizard`, and `BookingHistory`
contain zero references to `Wallet`/`WalletLedgerEntry`/
`WalletService`/`WalletLedgerService` — confirmed by grep and by
`test_successful_razorpay_payment_never_creates_wallet_or_ledger_rows`,
which asserts `Wallet::count() === 0` and `WalletLedgerEntry::count()
=== 0` after a full paid-and-confirmed booking flow.

Wallet recharge (topping up a wallet via Razorpay) and wallet-to-booking
payment (paying for a booking by debiting an existing wallet balance)
are both explicitly out of scope and not started. The natural future
shape: a recharge flow would create its own `booking_payments`-sibling
record type (or reuse this table with a `source_type = 'wallet_recharge'`)
whose webhook/checkout-verify handler calls
`WalletLedgerService::credit()` instead of
`BookingPaymentService::markPaid()`; a wallet-to-booking flow would call
`WalletLedgerService::debit()` from a new `BookingPaymentService`
option alongside (not instead of) the gateway path. Neither is built
here.

## Guest Paid Booking Flow — Decision: Implemented

The Phase 8 known gap ("guest paid booking flow not yet decided") is
resolved: guests can pay, authorized by the existing `manage_token`
credential — no new secret, no session. Two new endpoints under the
existing `/api/v1/guest/bookings/{reference}/payments/razorpay/`
prefix, both `throttle:guest-booking-write`-limited like every other
guest write:

- `POST .../initiate` — validates the token via
  `GuestBookingServiceInterface::findForGuest()`, asserts Razorpay is
  the active provider (422 if not), calls the existing
  `BookingPaymentService::initiate()`, returns the non-secret
  `checkoutPayload()` (`order_id`, public `key_id`, `amount_minor`,
  `currency`).
- `POST .../verify` — same token check, calls `verifyCheckout()`,
  reloads the booking, calls `markPaid()` if still payable. 401 on a
  forged signature, 422 on any other `BookingException`.

Covers both the golden path (pay immediately after the Livewire wizard
creates a guest booking — the wizard calls the underlying services
directly server-side, no token needed there since it's the same
request) and the retry path (a guest returns later via the manage link
and pays from that page, using this JSON API).

## Authenticated Student Paid Booking Flow

No new JSON API — every other authenticated-student surface in this
codebase is Livewire (`BookingWizard`, `BookingHistory`,
`WalletOverview`, `DashboardOverview`), so payment follows the same
pattern rather than introducing a second paradigm.

- `BookingWizard::initiatePayment()` / `verifyPayment()` — added
  alongside the existing `submit()` flow; step 7 shows a "Pay with
  Razorpay" button when `result.requires_payment` is true.
- `BookingHistory::initiatePayment()` / `verifyPayment()` — added to
  the existing booking-detail modal, gated by `Gate::authorize('pay',
  $booking)` (the pre-existing `BookingPolicy::pay()` ability), so a
  student can retry payment for their own pending/failed booking later.
- Both dispatch a `razorpay-checkout-ready` browser event
  (`order_id`, public `key_id`, `amount_minor`, `currency`, name,
  email) consumed by a shared Blade partial
  (`livewire/frontend/booking/partials/razorpay-checkout-script.blade.php`,
  wrapped in `@script`/`@endscript` in each component's view) that
  lazy-loads `checkout.razorpay.com/v1/checkout.js` and opens the
  Razorpay modal; its `handler` callback calls
  `$wire.verifyPayment(order_id, payment_id, signature)`.

`BookingWizardService::result()` gained two read-only fields
(`requires_payment`, `payment_status`) so the view can decide whether
to show the payment step — no other change to that service.

## Booking Lifecycle Boundary

Meeting creation remains untouched: `Booking::meeting_provider`/
`meeting_ref`/`meeting_url` are never set anywhere in this phase's
code, verified by `test_order_creation_does_not_mark_booking_paid_or_create_meeting`
and `test_successful_razorpay_payment_does_not_create_a_meeting`.
`markPaid()`'s existing auto-confirm behavior (`Pending` → `Confirmed`
when the type doesn't require approval) is unchanged and applies to
Razorpay exactly as it already did to the fake provider.

## Admin / Filament

`BookingPaymentResource` (`app/Filament/Resources/BookingPayments/`) —
view-only: no Create or Edit page, `canCreate()`/`canEdit()`/
`canDelete()`/`canDeleteAny()` all return `false`, no bulk actions.
Mirrors the `WalletResource` read-only pattern from Phase 9. Table/
infolist show booking reference, student (or "Guest"), provider,
amount, status, method, order/payment IDs (copyable, never the
signature), and timeline — never card/UPI details.

`BookingPaymentPolicy`: `viewAny`/`view` only (owner or
`View:BookingPayment` permission); `create`/`update`/`delete` all
`false`. `BookingPaymentPermissionSeeder` grants `ViewAny:BookingPayment`
/`View:BookingPayment` to `manager` by default (read-only support
access, matching the Wallet permission seeder's precedent) — there is
no `Manage:BookingPayment` permission because there is nothing to
manage; the only mutating actions are the gateway integration itself.

## Activity Logging

No direct `activity()` calls anywhere in this phase's code (verified by
grep). `BookingPaymentService::logPayment()` — unchanged, already
routes every `markPaid`/`markFailed`/`recordRefund` transition through
`AuditTrailService` and never logs secrets. `BookingPayment` uses
`LogsActivity` directly (same as `Wallet`/`Booking`), logging only
`status`/`provider`/`amount_minor`/`currency_code`. `RazorpayPaymentProvider`
itself never calls `Log::` or `activity()` — Razorpay API error
descriptions (safe, non-secret) surface only inside `BookingException`
messages.

## Out of Scope (unchanged from the explicit exclusion list)

Instructor payouts, subscriptions/packages, meeting creation, homework/
reviews/referrals expansion, wallet recharge, wallet-to-booking
payment, and the generic multi-gateway `PaymentWebhookController`
system were not built or touched.

## Tests

`tests/Feature/Booking/RazorpayCheckoutTest.php` (28 tests) — provider
config, order creation/idempotency, checkout-signature verification,
webhook verification (captured/failed/refunded/ignored/duplicate),
guest checkout, wallet/meeting boundary, admin authorization, and a
regression check that the Fake provider flow is unaffected.
`tests/Feature/Booking/RazorpayCheckoutLivewireTest.php` (3 tests) —
end-to-end wizard golden path, `BookingHistory` retry path, and the
`pay` policy boundary (a student cannot pay for another student's
booking).
