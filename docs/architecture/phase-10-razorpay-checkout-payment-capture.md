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

## Phase 10.2 — Terminal-State Hardening + Multi-Gateway Checkout

Phase 10.2 closes the Phase 10.1 audit's Finding 4 and generalizes the
Razorpay-only pipeline above into a real multi-gateway abstraction
(Razorpay + Stripe + Fake), without touching `booking_payments`'
schema, the wallet boundary, or the meeting boundary established
above. **No real Razorpay or Stripe credentials exist in this
environment** — every claim below about Stripe/Razorpay behavior is
verified with `Http::fake()`-stubbed HTTP responses, never a live
gateway call.

### Late-payment / terminal-state decision (Finding 4)

**Decision: reject, don't auto-refund.** `BookingPaymentService::markPaid()`
now calls a new `assertNotTerminal()` guard *before* `assertReference()`:

```php
if ($booking->status->isTerminal()) { throw new BookingException(...); }
```

`BookingStatus::isTerminal()` (`Completed`/`Cancelled`/`NoShow`) is
checked independently of `payment_status`, because
`CancelBookingAction` (Phase 7) never touches `payment_status` —
`booking:release-expired` cancels a lapsed reservation while
`payment_status` stays `Pending`, which is exactly the gap a late
async webhook could exploit. The rejection:

- logs to the booking's own timeline
  (`BookingActivityAction::PaymentStatusChanged`, actor `System`,
  `meta.rejected = 'late_payment_on_terminal_booking'`) and to the
  central Activity Log (`AuditTrailService::logSystem('payments',
  'payment_rejected_terminal', ...)`) — booking status and reference
  only, no gateway payload;
- never mutates `payment_status`, never clears `reserved_until`, never
  confirms the booking, never touches the wallet ledger, never sets a
  meeting field;
- surfaces as a `BookingException`, which
  `BookingPaymentWebhookController`'s existing catch block already
  turns into `{"status":"ignored"}` (200) for both Razorpay and Stripe
  webhooks — the provider stops retrying, no 401/500 is produced;
- a direct `markPaid()` call (not via webhook) simply throws — there is
  no controller catch to swallow it there, by design, since that path
  is only ever called internally right after `verifyCheckout()`/a
  webhook parse.

Auto-refund-on-late-success was considered and rejected for this pass:
the booking is already cancelled and its slot already released/reassignable,
so silently issuing a refund would touch the wallet/refund pipeline for
a case that isn't a refund of *anything the booking still represents*.
If a real gateway settles money for a rejected terminal booking, that
is an out-of-band reconciliation situation (a human refunds it via the
gateway dashboard) — this is documented, not automated, in
`payment-gateway-production-checklist.md`.

Covered by `tests/Feature/Booking/PaymentTerminalStateTest.php` (8
tests): cancelled/expired-reservation bookings rejected by direct
`markPaid()` call and by late Razorpay webhook, `payment_status`
unchanged after rejection, duplicate late webhook stays safe, no
meeting/wallet side effects.

### Multi-gateway architecture

Three new pieces sit between `BookingSettings::payment_provider` and
the existing `PaymentProviderRegistry`/`PaymentProviderInterface`:

- **`PaymentProviderCode`** (`app/Booking/Enums/PaymentProviderCode.php`)
  — `Fake`/`Razorpay`/`Stripe`, used wherever code needs to branch on
  provider identity instead of comparing raw strings (e.g. the Filament
  provider filter). `BookingSettings::payment_provider` itself stays a
  plain string — settings storage never depends on this enum.
- **`PaymentProviderConfigValidator`** (`app/Booking/Services/`) —
  format-only credential validation, never a network call.
  `isValidRazorpayKeyId()` (`rzp_(test|live)_...`),
  `isValidStripeSecretKey()`/`isValidStripePublishableKey()`
  (`sk_`/`pk_(test|live)_...`), `isValidStripeWebhookSecret()`
  (`whsec_...`). This is the direct fix for the explicit requirement
  that a random string in a credential field must never be treated as
  valid just because it's non-blank — both `RazorpayPaymentProvider::isConfigured()`
  and `StripePaymentProvider::isConfigured()` now call this before
  anything else. (Key *secrets* have no reliable public format and are
  only presence-checked, matching "optionally validate key_id format
  if reliable" from the phase brief.)
- **`PaymentProviderResolver`** (`app/Booking/Services/`) — the single
  safe seam between "which provider is selected" and "may it actually
  be used right now". `BookingPaymentService` now depends on this
  instead of `PaymentProviderRegistry` directly for `initiate()`/`refund()`/
  `checkoutPayload()`. Two checks a raw registry lookup skips:
  - the `fake` provider throws `BookingException` outside
    `local`/`testing` environments — selecting it in production fails
    loudly instead of silently accepting "payment" that moves no
    money;
  - a real provider that's selected but `!isConfigured()` (disabled, or
    credentials missing/malformed) throws immediately, with a clear
    admin-facing message, instead of failing deep inside an HTTP call.

  `BookingPaymentWebhookController` deliberately still uses
  `PaymentProviderRegistry` directly (unchanged) — a webhook must be
  signature-verified for whichever provider its URL names regardless of
  whether that provider is *currently* selected/enabled (this is the
  Phase 10.1 Finding 5 fix: gateway-enabled state must never gate
  signature verifiability). The resolver only guards *initiating new
  payments*, never *verifying webhooks that already arrived*.

`PaymentProviderInterface` gained three methods every provider
(`FakePaymentProvider`, `RazorpayPaymentProvider`, the new
`StripePaymentProvider`) now implements:

- `isConfigured(): bool` — enabled + credentials pass format
  validation; never a network call.
- `supportedCurrencies(): array` — Razorpay `['INR']`, Stripe
  `['USD','GBP','EUR','AED']` (INR is reserved for Razorpay by routing
  convention, not a Stripe limitation), Fake accepts any currency.
- `checkoutPayload(Booking $booking): array` — the gateway-neutral
  frontend response shape (see "Student/guest checkout flow" below).
  `BookingPaymentService::checkoutPayload()` is a thin passthrough to
  `$this->providers->current()->checkoutPayload($booking)`; the
  Razorpay Livewire wizard/history components and the guest payment
  controller now call `$this->payments->checkoutPayload(...)` instead
  of injecting `RazorpayPaymentProvider` directly for this step
  (`verifyCheckout()` — Razorpay's own client-callback signature check
  — stays Razorpay-specific, since Stripe has no equivalent; see
  below).

`PaymentIntentData` gained two additive, nullable fields
(`publicKey`, `clientSecret`) so `createPayment()`'s return value can
carry what a real gateway's frontend needs — non-breaking, since every
existing call site uses named arguments.

### Gateway routing strategy

`BookingSettings::payment_provider` remains the **only** selection
knob — there is no country/localization field on `Booking` to route
by (checked directly; none exists), so the "priority order" from the
phase brief collapses to: the global setting, guarded by
`PaymentProviderResolver` at the moment of use. Currency-based
routing is enforced per-provider instead of centrally: Razorpay
rejects non-INR in `createPayment()`, Stripe rejects anything outside
`['USD','GBP','EUR','AED']` — an admin who points
`payment_provider` at a provider that can't serve a given booking's
currency gets a clear `BookingException` at `initiate()` time, not a
partial/incorrect charge. `fake` is blocked outside
`local`/`testing` by the resolver (see above) — production never
silently falls back to it.

### Razorpay: webhook-only settlement gap (found while building Stripe)

While building Stripe's webhook handler, the same design question
surfaced for Razorpay: **if a payment settles via webhook alone**
(the payer closes the tab before Checkout.js's success callback
fires, so `verifyCheckout()` never runs), nothing previously updated
the `booking_payments` row itself to `Captured` — only
`verifyCheckout()` did that. `RazorpayPaymentProvider::refund()`
requires a `Captured` row with a `provider_payment_id` to find what to
refund, so a webhook-only-settled Razorpay payment could never later
be refunded. This was a latent gap in the original Phase 10 delivery
(not previously caught because every existing test called
`verifyCheckout()` before or instead of exercising the webhook path in
isolation).

**Fix:** both `RazorpayPaymentProvider::parseWebhook()` and
`StripePaymentProvider::parseWebhook()` now call a private
`settlePaymentRow()` after signature/amount/currency verification,
which marks the `booking_payments` row `Captured` (+ `provider_payment_id`,
`paid_at`) or `Failed` (+ `failed_at`) for the corresponding normalized
event — idempotent by construction (a row already in a terminal status
is left untouched). This is a booking_payments *row* consistency fix
only; it does not change `Booking.payment_status`/`status` transitions,
which remain exclusively `BookingPaymentService`'s responsibility.

### Stripe provider

`StripePaymentProvider` (`app/Booking/Payments/StripePaymentProvider.php`)
mirrors `RazorpayPaymentProvider`'s shape — raw `Http` calls, no SDK
dependency, same idempotency discipline (`booking_payments` unique
`idempotency_key` + `UniqueConstraintViolationException` recovery) —
but Stripe's model differs in two ways:

- **No client-side checkout-verify step.** Stripe Elements/Checkout
  confirms the PaymentIntent directly with Stripe using
  `client_secret`; this integration settles a booking only from the
  server-to-server webhook (`payment_intent.succeeded`), never a
  client callback. There is deliberately no `verifyCheckout()`
  equivalent.
- **`client_secret` is never persisted.** It's single-use and
  frontend-facing. `createPayment()`'s fresh-creation path returns it
  directly from Stripe's create-intent response (no extra call);
  `checkoutPayload()` re-fetches it from Stripe's retrieve-intent
  endpoint on demand rather than ever writing it to
  `booking_payments.metadata`. The idempotent-reuse path inside
  `createPayment()` (a repeated `initiate()` call) does **not**
  re-fetch it either, since that return value isn't what the frontend
  actually uses — re-fetching there would turn a free idempotent
  no-op into an avoidable Stripe API call
  (`test_stripe_intent_creation_is_idempotent_on_repeated_initiate`
  asserts exactly one HTTP call across two `initiate()` calls).

Webhook signature verification reuses
`PaymentWebhookSignatureService::verifyStripe()` — this already
existed (built for the generic, non-booking `PaymentWebhookController`
scaffold) and is now wired to actual booking settlement for the first
time via `StripePaymentProvider::parseWebhook()`, which requires
`stripe_webhook_secret` to be present (unlike the shared service's own
permissive "allow if no secret configured" default, which this
provider's own `blank($secret)` guard overrides). Amount/currency
mismatch on `payment_intent.succeeded` throws
`InvalidPaymentWebhookException` (401), mirroring Razorpay's Finding-6
fix exactly. Refund uses Stripe's Refunds API
(`POST /v1/refunds`), same failure-safety as Razorpay's refund path.

**Frontend Stripe.js/Elements integration is not built in this
phase** — no real Stripe credentials exist to test against, and no
India/non-INR checkout UI currently exists to wire it into. The
backend provider (order/intent creation, webhook processing, refund,
credential validation) is complete and tested with stubbed HTTP
responses; wiring an actual Stripe Elements UI is future work once
real sandbox credentials are available. `checkoutPayload()` already
returns everything a Stripe Elements/Checkout frontend would need
(`payment_intent_id`, `client_secret`, `publishable_key`,
`amount_minor`, `currency`).

Covered by `tests/Feature/Booking/StripeCheckoutTest.php` (17 tests):
config/credential-format rejection, integer minor units, secret
exposure, idempotent order creation + failure handling, webhook
verification (succeeded/failed/amount-mismatch/currency-mismatch/
forged-signature/duplicate), wallet/meeting boundary, active refund,
and a regression check that the Fake provider flow is unaffected by
Stripe's registration.

### Provider abstraction tests

`tests/Feature/Booking/PaymentProviderResolverTest.php` (9 tests):
resolver returns Fake in testing, returns Razorpay/Stripe when
configured, rejects disabled/misconfigured/unregistered providers,
rejects a random-text Razorpay key_id, and — via a mocked
`Illuminate\Contracts\Foundation\Application` — proves the fake
provider is rejected outside `local`/`testing` and accepted inside it.

### Student/guest checkout flow — unchanged decisions, generalized seam

The Phase 10 decisions to use Livewire (not a JSON API) for
authenticated students and a token-authorized JSON API for guests are
unchanged in this phase — both are low-risk, already-audited, already-tested
surfaces, and rewriting them was unnecessary to achieve gateway
neutrality. What changed: `BookingWizard::initiatePayment()`,
`BookingHistory::initiatePayment()`, and
`GuestBookingPaymentController::initiate()` now call
`$this->payments->checkoutPayload($booking)` (gateway-neutral,
resolver-backed) instead of injecting `RazorpayPaymentProvider`
directly for that one call — behaviorally identical for Razorpay
(same method, same return shape, now with an added `provider` key),
but the seam is no longer hardcoded to one gateway class.

Guest checkout remains **Razorpay-only by explicit design** in this
phase (`GuestBookingPaymentController::initiate()` still 422s if
`payment_provider !== 'razorpay'`) — see "Guest paid booking flow"
below.

### Guest paid booking flow — decision unchanged: Razorpay-only, token-authorized

Re-affirmed, not rebuilt: guest checkout stays scoped to Razorpay in
this phase. Extending it to Stripe would require either a second guest
route pair or a provider-branching single route, and guest paid
checkout was already a deliberately conservative, previously-audited
surface (Phase 10.1, §8) — broadening it was not requested and adds
guest-facing attack surface for a gateway with zero real credentials
configured anywhere in this environment. If Stripe guest checkout is
wanted later, the seam is ready: `GuestBookingPaymentController::initiate()`
already calls the gateway-neutral `$this->payments->checkoutPayload()`
internally (just gated to razorpay by an explicit settings check
first).

### Credential validation behavior

Both `RazorpayPaymentProvider::isConfigured()`/`assertConfigured()` and
`StripePaymentProvider::isConfigured()`/`assertConfigured()` now check
format, not just presence:

- Razorpay: `razorpay_enabled` + `razorpay_key_id` matches
  `rzp_(test|live)_...` + `razorpay_key_secret` present.
- Stripe: `stripe_enabled` + `stripe_secret_key` matches
  `sk_(test|live)_...` + `stripe_publishable_key` matches
  `pk_(test|live)_...`. `stripe_webhook_secret` is separately required
  (format `whsec_...`) inside `parseWebhook()` specifically, since
  webhook verification doesn't need the other two fields (same
  Finding-5 precedent as Razorpay).

**No credential ever calls the gateway during validation** — this is
pure regex format-checking against Stripe's and Razorpay's documented,
stable key-prefix conventions. A random string like `"asdf1234"` in
any credential field fails `isConfigured()` immediately and
`PaymentProviderResolver` refuses to hand out that provider. This was
verified directly: this environment's `PaymentGatewaySettings` has no
real Razorpay/Stripe credentials configured (both disabled, all fields
blank per the Phase 10.1 audit's live-DB check), and
`test_stripe_rejects_random_unformatted_credentials`/
`test_resolver_rejects_razorpay_with_random_unformatted_key_id` prove
the rejection path explicitly.

### Security rules — re-confirmed for this phase

Grep across `RazorpayPaymentProvider`, `StripePaymentProvider`,
`PaymentProviderResolver`, `PaymentProviderConfigValidator`, and every
touched Livewire/controller file: no `stripe_secret_key`,
`razorpay_key_secret`, `stripe_webhook_secret`,
`razorpay_webhook_secret`, or `client_secret` is ever logged,
serialized into an API/Livewire response, or written to
`booking_payments.metadata`. `checkoutPayload()` for both providers
returns only non-secret, single-use-or-public values. Terminal-state
rejection logging (`assertNotTerminal()`) includes only booking
reference/status — no gateway payload.

### Wallet / meeting boundary — unchanged, re-verified for Stripe

Grep across `StripePaymentProvider`, and re-run of the existing
Razorpay wallet/meeting boundary tests: zero references to
`Wallet`/`WalletLedgerEntry`/`WalletService`/`WalletLedgerService` in
any Phase 10.2 file; `meeting_provider`/`meeting_ref`/`meeting_url` are
never assigned. `test_successful_stripe_payment_never_creates_wallet_or_ledger_rows`
and the terminal-state suite's `test_no_meeting_created_for_a_rejected_late_payment`
/`test_no_wallet_ledger_entry_created_for_a_rejected_late_payment`
confirm this directly for both the new Stripe path and the terminal-state
rejection path.

### Out of scope (unchanged from Phase 10, explicitly re-confirmed)

Wallet recharge, wallet-to-booking payment, instructor payouts,
subscriptions/packages, meeting creation, and the generic multi-gateway
`PaymentWebhookController`/`PaymentWebhookProcessor` scaffold were not
built or touched in this phase either. Auto-refund-on-late-terminal-success
was considered (see "Late-payment / terminal-state decision" above) and
explicitly not built — rejection is the implemented behavior.

### Tests added in Phase 10.2

- `tests/Feature/Booking/PaymentTerminalStateTest.php` — 8 tests.
- `tests/Feature/Booking/StripeCheckoutTest.php` — 17 tests.
- `tests/Feature/Booking/PaymentProviderResolverTest.php` — 9 tests.

All pre-existing Phase 10/10.1 tests
(`RazorpayCheckoutTest.php`, `RazorpayCheckoutLivewireTest.php`) pass
unmodified except for the additive `settlePaymentRow()` behavior,
which no existing assertion contradicts (verified by re-running the
full suite after each change in this phase).

## Phase 10.2A — Admin Gateway Setup, Official SDKs, Country Routing

Phase 10.2A is a business-decision follow-up to 10.2: replace the
raw-`Http` Razorpay/Stripe calls with the official SDKs, and build the
admin-facing configuration/readiness/routing layer around them. No
frontend student/guest checkout work was done in this pass — that
remains Livewire/JSON-API surfaces already built in Phase 10/10.2,
untouched here.

### Official SDK installation — isolated behind adapters

`composer require razorpay/razorpay stripe/stripe-php` (versions
2.9.3 / v20.3.0 at install time). **Laravel Cashier is not installed**
— confirmed via `composer show | grep -i cashier` (no output) —
because Cashier models subscription billing (plans, invoices, proration),
which this codebase does not use; a one-off booking checkout has no
subscription concept to manage.

Both SDKs are isolated behind a one-method-per-operation adapter
interface, never instantiated anywhere else:

- `App\Booking\Contracts\RazorpayGatewayClient` /
  `App\Booking\Gateways\RazorpaySdkClient` (wraps `\Razorpay\Api\Api`).
- `App\Booking\Contracts\StripeGatewayClient` /
  `App\Booking\Gateways\StripeSdkClient` (wraps `\Stripe\StripeClient`).

`RazorpayPaymentProvider`/`StripePaymentProvider` depend on the
interfaces, not the concrete SDK-backed classes — bound in
`BookingServiceProvider::bindInterfaces()`. Credentials are passed as
per-call arguments (`createOrder(string $keyId, string $keySecret, array $params)`,
etc.), not constructor state, since they come from
`PaymentGatewaySettings` at the moment of use and can change between
requests if an admin updates settings. A new `GatewayRequestException`
(`app/Booking/Exceptions/`) is the only exception type that crosses
this boundary — each provider catches it and raises a `BookingException`
with a safe, contextualized message; the raw SDK exception is never
allowed to propagate un-translated.

Tests bind a `Mockery` fake of the adapter interface
(`$this->app->instance(RazorpayGatewayClient::class, $mock)`) instead
of stubbing HTTP/cURL — the SDKs' own transport layers (Razorpay uses
`rmccue/requests`, not Guzzle) are not reliably interceptable via
`Http::fake()`, so faking one level higher, at the adapter seam this
codebase itself defines, is both correct and the only practical option.
All 78 existing Razorpay/Stripe/terminal-state/resolver tests were
updated to this pattern and still pass; no test's assertions changed,
only how the gateway response is stubbed.

### PaymentGatewaySettings additions — additive only, zero behavior change by default

New fields (migration `2026_07_17_100000_add_gateway_readiness_fields_to_payment_gateway_settings.php`):

- Platform-wide: `default_provider` (nullable, starts null),
  `payments_enabled` (kill switch, default true), `allow_guest_checkout`,
  `allow_student_checkout` (both default true, documenting current
  behavior — not yet enforced by a checkout entry point, since guest/student
  checkout wiring is unchanged from Phase 10/10.2), `allowed_providers`
  (array, default `[]` = no restriction), `production_ready_at`,
  `last_configuration_checked_at` (both nullable timestamps).
- Per-provider: `{provider}_config_status` (`not_configured`/`incomplete`/
  `invalid`/`ready`, default `not_configured`), `{provider}_last_checked_at`,
  `{provider}_supported_currencies` (informational mirror of each
  provider's `supportedCurrencies()` — the provider class remains the
  enforced source of truth, this is display-only).

**Deliberately not added:** a `razorpay_environment`/`stripe_environment`
test/live enum. The existing `razorpay_sandbox_mode`/`stripe_sandbox_mode`
booleans already serve exactly this purpose and are already wired into
the admin UI and every test — adding a second, parallel field would
risk the two drifting out of sync. `sandbox_mode = true` **is** "test
environment", `false` **is** "live environment"; documented here
instead of duplicated in the schema.

`default_provider` starts `null` specifically so this migration changes
**zero** existing behavior: `PaymentProviderResolver` only consults it
if an admin has explicitly set it (see routing order below).

### Gateway routing strategy — updated priority order

`PaymentProviderResolver::resolveKey()` (updated from Phase 10.2's
single-knob design):

1. `Country::payment_routing` (existing, previously-unwired JSON column
   from the localization foundation phase — `{"provider": "razorpay",
   "enabled": true, "notes": "..."}`) when a country is passed to
   `current(string $countryIso2)`.
2. `PaymentGatewaySettings::default_provider`, when set.
3. `BookingSettings::payment_provider` — the original Phase 7 knob,
   still authoritative for every existing checkout flow that doesn't
   pass a country (which is all of them today — country-aware
   resolution is a capability, not yet wired into `BookingPaymentService::initiate()`,
   since that would require threading country context through the
   booking/checkout call chain, left for the frontend-checkout
   sub-phase).

New gates in `resolve()`, checked before the fake/real-provider split:

- `payments_enabled = false` blocks every provider, fake included — a
  platform-wide pause button.
- `allowed_providers`, when non-empty, restricts resolution to that
  list regardless of what's individually enabled/configured.

`Country::payment_routing` reuse: no migration was needed (`payment_routing`
already existed, nullable JSON, documented in
`docs/archive/reports/localization-foundation.md` (historical) as explicitly
unimplemented routing logic — "This phase deliberately does not
implement: ... Payment gateway selection logic"). `CountryForm` gained
a "Payment Routing" section (`payment_routing.provider` select,
`payment_routing.enabled` toggle, `payment_routing.notes` textarea) —
no new Filament resource, the existing `CountryResource` form only.

### PaymentGatewayConfigurationService — admin-facing readiness, distinct from the resolver

`PaymentGatewayConfigurationService::checkRazorpay()`/`checkStripe()`/`checkFake()`
determine and **persist** `{provider}_config_status` +
`{provider}_last_checked_at`. This is deliberately a different
responsibility from `PaymentProviderResolver`/`isConfigured()`:

- The resolver's `isConfigured()` check is real-time and unpersisted —
  re-evaluated on every `initiate()` call, never cached, because a
  stale "ready" status must never let a payment through after
  credentials are revoked.
- The configuration service is the "click Validate Configuration, see
  a status badge" admin bookkeeping layer — persisted so the settings
  page can render a status without recomputing it on every page load,
  and so `last_checked_at` has a meaningful audit trail.

Both share the same `PaymentProviderConfigValidator` format checks
underneath, so they can never disagree about whether a given credential
*string* is well-formed — they can only disagree about *staleness*
(the persisted status could be stale if credentials changed without
re-running Validate Configuration; the resolver never has this problem
since it re-checks every time).

**Never calls the gateway network** — format/settings inspection only,
confirmed by `PaymentGatewayConfigurationServiceTest`'s
`test_config_status_never_calls_the_gateway_network`, which runs with
no `Http::fake()`/mocked SDK client bound at all and still passes.

`PaymentSettingsPage`'s existing "Validate Credentials" header action
now routes Razorpay/Stripe through this service (persisting the form's
current, possibly-unsaved gateway fields first via
`saveGatewaySettings()`, then checking them) instead of its previous
blank-field-only check; PayPal/Cashfree/PayU/PhonePe keep the original
behavior unchanged, since building out equivalent readiness services
for gateways with zero implementation behind them was out of scope. A
random/incomplete credential now surfaces the explicit warning:
"Random or placeholder credentials are not valid. Checkout will remain
disabled until credentials pass validation." The Razorpay/Stripe tab
badges now show the persisted config status (Ready/Incomplete/Invalid
credentials/Not configured, color-coded) instead of a plain
Enabled/Disabled toggle state. A new "Mark Production Checklist
Reviewed" action records `production_ready_at` only — it flips no
`*_enabled` flag and does not gate anything by itself; it exists purely
as an audit trail that an admin walked through
`payment-gateway-production-checklist.md`.

### Random-credential safety — re-confirmed end to end

Every layer that decides "is this gateway usable" —
`RazorpayPaymentProvider::isConfigured()`/`assertConfigured()`,
`StripePaymentProvider::isConfigured()`/`assertConfigured()`,
`PaymentProviderConfigValidator`, and now
`PaymentGatewayConfigurationService` — rejects a non-blank-but-malformed
credential the same way: format regex against each gateway's documented,
stable key-prefix convention (`rzp_(test|live)_...`, `sk_(test|live)_...`,
`pk_(test|live)_...`, `whsec_...`), never a network call. This
environment's `PaymentGatewaySettings` has no real Razorpay/Stripe
credentials anywhere — both gateways remain `*_enabled = false` with
blank credential fields — confirmed by this phase not writing to those
fields anywhere except inside test setup helpers (`Crypt::encryptString('test_key_secret')`-style
fixtures, never a real key).

### Boundaries re-confirmed unchanged

No student/guest frontend checkout UI was added or modified (Livewire
`initiatePayment()`/`verifyPayment()` and the guest JSON endpoints are
byte-for-byte unchanged from Phase 10.2 except the `checkoutPayload()`
indirection already documented there). No wallet ledger mutation was
added — grep across every new/changed file in this sub-phase for
`Wallet`/`WalletLedgerEntry`/`WalletService`/`WalletLedgerService`:
zero matches. No meeting field is assigned anywhere in this sub-phase's
code. No duplicate `Payment`/`Transaction`/wallet/booking/meeting table
was created — `booking_payments` schema is unchanged (no migration
touched it); the only new tables/columns are the additive
`PaymentGatewaySettings` keys (a settings migration, not a schema
migration) and the reused, pre-existing `countries.payment_routing`
column.

### Tests added in Phase 10.2A

- `tests/Feature/Booking/PaymentGatewayConfigurationServiceTest.php` — 10 tests.
- `PaymentProviderResolverTest.php` — 7 new tests (kill switch,
  allowed-providers restriction, default_provider priority, legacy
  fallback, and three country-routing scenarios), for 16 total in that
  file.
- All of `RazorpayCheckoutTest.php` (34), `PaymentTerminalStateTest.php` (8),
  `StripeCheckoutTest.php` (17), and `RazorpayCheckoutLivewireTest.php` (3)
  were updated in-place to mock the new adapter interfaces and still pass.

Full suite: **2027/2027 passing**, 4548 assertions (up from 2010/2010
at Phase 10.2's completion — 17 additional tests: 10 for the
configuration service, 7 for the resolver's new routing/gates).

## Phase 10.2B — Option B (Late Payment → Wallet Credit) + Country-Aware Checkout Wiring

Two changes, both explicit business decisions from the Phase 10.1/10.2A
audits: (1) replace Phase 10.2's outright *rejection* of a late gateway
success on a terminal booking with **Option B** — preserve the charge
and redirect it to the student's wallet instead of discarding it or
confirming a dead booking; (2) wire the country-aware provider
resolution built in Phase 10.2A (but never connected to an actual
checkout call) into `BookingPaymentService::initiate()`/`checkoutPayload()`.

### Option B — decision and behavior

**Why the decision changed from Phase 10.2's "reject":** rejecting a
late success is safe but throws away real money that a payer already
paid — the charge is genuine (server-verified signature, amount, and
currency, exactly as before) and Razorpay/Stripe already captured it.
Silently discarding that is worse for the business than crediting it
somewhere recoverable. Auto-refunding to the original payment method
was considered and explicitly deferred to a future phase (gateway
refund APIs, already built in Phase 10, are reused for *active*
refunds via `BookingPaymentService::refund()`, but are not invoked
here — see "Why not call the gateway refund API" below).

`BookingPaymentService::markPaid()` now branches on
`$booking->status->isTerminal()` **after** `assertReference()` (so a
forged/mismatched reference is still rejected exactly as before,
terminal or not):

```
markPaid():
  assertReference()                         // unchanged — authenticity gate
  if booking.status.isTerminal():
      → handleLateTerminalPayment()         // Option B (this phase)
  else:
      → existing paid + confirm flow        // unchanged
```

`handleLateTerminalPayment()` (`BookingPaymentService`):

- **Student booking** (`!$booking->isGuest()`): credits the student's
  wallet via the existing `WalletService::getOrCreateWallet()` +
  `WalletLedgerService::credit()` (Phase 9 services, reused as-is, no
  wallet code duplicated). `Booking.payment_status` becomes `Refunded`
  — the closest existing enum value to "this charge was not retained
  as this booking's revenue" (there is no dedicated "credited" status
  and adding one was judged unnecessary scope for this phase). The
  booking's own `status` is **never** touched — it stays exactly as
  terminal as it already was (Cancelled/Expired-via-cancellation/
  Completed/NoShow), never reconfirmed, never re-reserved.
- **Guest booking** (no user account to hold a wallet): the capture is
  preserved at the `booking_payments` row level, `Booking.payment_status`
  is **left unchanged** (stays whatever it already was, typically
  `Pending`) rather than claiming a resolution ("Refunded") that didn't
  actually happen — the row's `metadata.manual_resolution_required`
  flag is what an admin/support workflow acts on instead.
- **Wallet credit itself fails** (closed wallet, or the booking's
  currency has no active `Currency` row — `WalletService::resolveCurrency()`
  throws `ValidationException` here, not `WalletException`, which is
  why the catch in `tryCreditStudentWallet()` is deliberately
  `catch (\Throwable)`, not `catch (WalletException)` — the wallet
  subsystem's failure modes are heterogeneous and an uncaught exception
  reaching `BookingPaymentWebhookController` would surface as a raw
  500, not a safe `{"status":"ignored"}`): falls back to the same
  guest-style manual-resolution flag.

**Idempotency — two independent layers**, since the student and guest
paths are only sometimes protected by the same mechanism:

1. A `booking_payments.metadata.late_terminal_handled` flag, checked
   first (behind a `lockForUpdate()` on the row) before any other work
   — this is the only guard that protects the **guest** path, since
   guest bookings leave `payment_status` at `Pending` (deliberately,
   see above), so a duplicate webhook delivery would otherwise pass
   `assertReference()` again on every retry.
2. For the **student** path, `payment_status` flips to `Refunded` on
   the first successful credit, so a second delivery fails
   `assertReference()`'s own `payment_status === Pending` check before
   `handleLateTerminalPayment()` is even reached — belt-and-suspenders
   with (1). `WalletLedgerService::credit()`'s own idempotency key
   (`late-payment-credit:{booking_payment_id}:{provider_payment_id}`)
   is a third, independent layer specifically for the wallet ledger
   entry itself.

**No meeting, no confirmation, no instructor payout, no re-reservation**
in any branch — verified by
`test_no_meeting_created_for_late_terminal_payment` and every Option B
test asserting `$booking->status` is unchanged.

### Why not call the gateway refund API

Deliberately not done in this phase (per the approved Option B spec):
calling `RazorpayPaymentProvider::refund()`/`StripePaymentProvider::refund()`
here would push money back to the payer's original card/UPI/bank —
Option B's whole premise is that the money should become usable
**wallet credit** for the student's next booking, not disappear back
out of the platform. `booking_payments.status` is left `Captured` (the
true gateway-side state, set by each provider's `settlePaymentRow()`
in Phase 10.2A) — only `Booking.payment_status` reflects the
booking-level "this charge isn't this booking's revenue" outcome.

### Wallet ledger integration — new entry type, no schema change

`WalletLedgerEntryType::LatePaymentCredit` (`late_payment_credit`) was
added — checked first whether this was safe: `wallet_ledger_entries.entry_type`
has **no** database `CHECK` constraint (only `direction` and
`amount_minor` do), so a new PHP enum case requires no migration.
`Refund` (the existing case) was considered and rejected as a reuse
target — it would blur reporting between "an existing paid booking
being actively refunded" and "a payment that never became this
booking's revenue in the first place," which are different events for
support/analytics purposes.

`WalletService`/`WalletLedgerService` were reused exactly as built in
Phase 9 — no wallet code was added or duplicated, only a new call site.
`WalletLedgerService::credit()`'s own two-layer idempotency-key check
(pre-lock and post-lock, backed by a unique DB index on
`idempotency_key`) is trusted as-is.

### Wallet feature flag — confirmed, not changed

Audited before writing any code: neither `WalletService` nor
`WalletLedgerService` reads `FeatureSettings::wallet_enabled` anywhere
— it is purely a UI/controller visibility gate (`StudentWalletController`
404s the page, the dashboard widget and account-menu item hide
themselves). This matches the preferred "enterprise financial
correctness" behavior verbatim: **internal system wallet credits are
allowed regardless of the UI flag**, since this is already how the
Phase 9 services behave and Phase 10.2B does not change it. Documented
here rather than re-implemented, since building a redundant
double-gate would only risk the two disagreeing.

### Guest late payment — decision: preserve + flag, no account-claim flow built yet

`BookingPayment.metadata` gains two keys for the guest path:
`manual_resolution_required: true` and a human-readable
`manual_resolution_reason`. No wallet is created for a guest under any
circumstance. An account-claim flow (a guest later registers and an
admin credits their new wallet from the flagged payment) is
**documented as future work, not built** — this phase only makes the
captured-but-unresolved state visible and filterable in the admin
`BookingPaymentsTable`/`BookingPaymentInfolist` ("Resolution" column/section,
new `manual_resolution_required` table filter), so support has
something to act on.

### Country-aware provider resolution — wired into initiate()/checkoutPayload()

`PaymentProviderResolver::current(?string $countryIso2)` already
existed (Phase 10.2A) but no caller ever passed a country. Fixed at
the source: `BookingPaymentService::provider(?Booking $booking = null)`
now resolves the payer's country and passes it through, used by both
`initiate()` and `checkoutPayload()` (kept consistent with each other
— both calls happen back-to-back for the same booking in the same
request). `refund()` deliberately still calls the country-agnostic
`current()` — re-deriving a *possibly different* provider for an
already-captured payment at refund time would risk resolving the wrong
gateway for that specific captured row; this is unchanged, pre-existing
behavior, not a new risk introduced here.

`resolveCountryIso2(Booking $booking)`:

- Guest bookings: always returns `null` — **no country field exists
  anywhere on a guest booking** (checked directly against the `Booking`
  model and migrations; confirmed by
  `test_guest_booking_has_no_country_signal_and_uses_default_provider`).
  Guest checkout always falls through to `default_provider`/legacy
  `BookingSettings::payment_provider`, exactly as before this phase.
- Student bookings: `$booking->attendee?->profile?->country?->iso2` —
  reuses the existing `UserProfile::country_id` → `Country` relation
  (already present, unrelated to payments, from the localization
  foundation phase). No new field, no migration.

This slots into `PaymentProviderResolver`'s existing routing order
unchanged (`Country::payment_routing` → `PaymentGatewaySettings::default_provider`
→ `BookingSettings::payment_provider`) — Phase 10.2B only supplies the
country argument that was previously always `null`.

### Tests added in Phase 10.2B

- `PaymentTerminalStateTest.php` — rewritten in place (Option B
  supersedes Phase 10.2's rejection tests): 10 tests covering direct
  `markPaid()` calls, late Razorpay webhooks, duplicate-delivery
  idempotency, amount-mismatch safety, and guest manual resolution.
- `StripeCheckoutTest.php` — 2 new tests for the same Option B
  behavior via the Stripe webhook path (19 total in that file).
- `CountryAwareProviderResolutionTest.php` — 7 new tests: India→Razorpay,
  US→Stripe, UK→Stripe, not-ready-provider failure, null-country
  fallback, random-credential rejection under country routing, and
  guest bookings ignoring country routing entirely.

Full suite: **2039/2040 passing**, 4586 assertions (up from 2029/2029
at Phase 10.2A's completion — net +11 tests: `PaymentTerminalStateTest`
rewritten from 8 to 10, `StripeCheckoutTest` 19→21, plus the new
7-test `CountryAwareProviderResolutionTest`). The one non-passing test
(`RegistrationIntegrationTest::test_successful_registration_creates_user`)
is an unrelated, pre-existing flake in the Security/Registration
domain — confirmed by re-running it in isolation (44/44 passing) — not
a regression from this phase; no Payment/Wallet/Booking file is
involved in that test.

### Out of scope, unchanged

Instructor payouts, subscriptions/packages, meeting creation, recurring
wallet deduction, and gateway-side refund-to-original-method for the
Option B scenario were not built — all explicitly deferred per the
approved spec. Frontend Stripe Elements/Checkout UI remains deferred
from Phase 10.2/10.2A.

## Phase 10.2C — Authenticated Student Checkout Frontend

Scope: make the authenticated student checkout UI (which already
existed from Phase 10 — `BookingWizard` step 7 and `BookingHistory`'s
booking-detail modal both already had a "Pay" button, `initiatePayment()`/
`verifyPayment()`, and a Razorpay Checkout.js Blade partial) safe and
correct now that the backend supports **three** providers, not one.
Guest checkout UI, instructor payment UI, and Stripe frontend
Elements/Checkout were explicitly out of scope for this pass.

### The gap this phase closes

Both Livewire components' `initiatePayment()` unconditionally dispatched
a `razorpay-checkout-ready` browser event built from `$paymentOrder['order_id']`/
`['key_id']` — keys that only exist in **Razorpay's** `checkoutPayload()`
shape. If `BookingSettings::payment_provider` (or, since Phase 10.2B,
country-based routing) ever resolved to Stripe or fake, this would
silently dispatch an event with missing/null keys, opening a broken
Razorpay modal for a payment that was never actually a Razorpay order.
Never triggered in practice before this phase because Razorpay was the
only provider ever exercised end-to-end from the UI — but the gap was
real and would have surfaced the moment Stripe or a country route was
switched on.

**A second, more serious gap** was found while auditing the "Pay Now"
button conditions: `BookingPaymentService::initiate()` checked
`payment_status->isPayable()` but **never checked `booking->status->isTerminal()`**.
A cancelled booking whose reservation-hold race left `payment_status`
still `Pending` (the exact scenario Phase 10.2's Option B exists to
recover from) could still have `initiate()` called on it — creating a
*brand-new* gateway order for a booking that can never be confirmed.
Option B (Phase 10.2B) would still recover the money safely as a
wallet credit if the student went through with paying it, but that is
a bad-UX safety net, not a substitute for blocking the attempt before
a real charge happens. Fixed by adding the same `isTerminal()` guard
`markPaid()` already had, at the top of `initiate()`:

```php
if ($booking->status->isTerminal()) {
    throw new BookingException(...);
}
```

Covered by `test_cancelled_booking_cannot_initiate_a_new_payment`. The
Blade "Pay Now" box also gained a `$isActive` (`! isTerminal()`) guard
as defense-in-depth — the backend check is the real boundary, the UI
guard only avoids showing a button that would immediately fail.

### Provider-neutral dispatch

`BookingWizard::initiatePayment()` and `BookingHistory::initiatePayment()`
now branch on `$paymentOrder['provider']` (returned by
`BookingPaymentService::checkoutPayload()`, unchanged from Phase 10.2):

- `razorpay` → dispatches `razorpay-checkout-ready` exactly as before
  (Checkout.js opens, `handler` calls `$wire.verifyPayment(...)`).
- `stripe` → sets the banner "Card payment via Stripe is coming soon.
  Please contact support to complete this payment." and dispatches
  nothing — no Stripe Elements/Checkout UI is built in this phase (see
  "Stripe frontend decision" below). The backend call
  (`initiate()`/`checkoutPayload()`) still runs and still returns
  `client_secret`/`publishable_key` correctly — only the frontend
  choosing not to act on it is new here.
- `fake` → dispatches nothing either; the view instead reveals
  **"Simulate success"/"Simulate failure"** buttons, visible only when
  `app()->environment(['local', 'testing'])` (checked directly in
  Blade, matching `PaymentProviderResolver`'s own gate) and only while
  a fake payment is genuinely in flight (`$paymentOrder['provider'] === 'fake'`).

### Fake provider — local/testing simulation

New `simulateFakePayment(bool $success)` on both Livewire components:
re-checks `app()->environment(['local', 'testing'])` itself before
doing anything (not just trusting the button being hidden — the button
not rendering in production is a UX nicety, the environment check
inside the method is the actual safety boundary, mirroring
`PaymentProviderResolver::resolve()`'s identical guard for the `fake`
key). On success, calls the existing `BookingPaymentService::markPaid()`;
on failure, `markFailed()` — no new payment-settlement code path, this
only gives testers/automation a UI-reachable way to trigger the
existing ones without a real gateway. `Gate::authorize('pay', ...)`
still runs first in `BookingHistory`, so this cannot be used to pay
another student's booking either.

### Stripe frontend decision — deferred, confirmed correct choice

Per the explicit instruction to keep Stripe frontend deferred and
focus on Razorpay (primary settlement is INR): no Stripe.js, no
Elements, no PaymentIntent confirmation UI was built. The backend
(`StripePaymentProvider`, `checkoutPayload()` returning `client_secret`/
`publishable_key`, webhook settlement) is complete and tested (Phase
10.2/10.2A) and ready for a future frontend to consume — nothing in
this phase changed the Stripe backend contract. `test_stripe_selected_shows_safe_coming_soon_message_and_leaks_no_secret`
confirms the deferred message renders and that `stripe_secret_key`,
`stripe_webhook_secret`, and the PaymentIntent's `client_secret` never
appear in the component's rendered HTML — even though `checkoutPayload()`
is called and does return `client_secret` to the component's PHP-side
`$paymentOrder` property, the Blade view never echoes it anywhere.

### Student payment status UX

`booking-history.blade.php`'s detail modal gained explicit status
messaging beyond the existing pending/failed box:

- `paid` → a plain "Paid" confirmation.
- `refunded` → "Refunded", unless `BookingHistory::paymentWasCreditedToWallet()`
  (a cheap `booking_payments.metadata->wallet_ledger_entry_id` existence
  check, no sensitive data) is true, in which case: "Payment received
  after this booking's slot was released — the amount was credited to
  your wallet." — the only place in the frontend that surfaces Option B
  (Phase 10.2B) to the student who actually benefited from it. Guest
  manual-resolution state is never shown to a student (it cannot be —
  guest bookings have no student-portal session to view it from) and
  no other student's payment details are ever queried, since the
  wallet-credit check is scoped to `$this->selectedBooking`, which is
  only ever set after the existing `'view'` policy gate passes.

### Guest and instructor boundaries — unchanged, re-confirmed

No guest checkout UI was added, modified, or expanded — the guest JSON
API (`GuestBookingPaymentController`) and Phase 10.2B's guest
manual-resolution path are untouched. No instructor payment UI was
built — instructors continue to see only booking/lesson status, never
provider IDs, student payment methods, wallet details, or margin.

### Tests added in Phase 10.2C

`tests/Feature/Booking/StudentCheckoutFrontendTest.php` — 11 tests:
Pay Now visibility (own unpaid/free-demo/already-paid/cancelled
booking, another student forbidden), the new `initiate()` terminal
guard, duplicate-initiation idempotency, fake-provider success/failure/
production-blocked simulation, and the Stripe deferred-message/no-secret-leak
check. One existing test
(`RazorpayCheckoutLivewireTest::test_student_can_pay_via_the_booking_wizard`)
was updated in place for the button label change ("Pay with Razorpay" →
"Pay now", now provider-neutral) — no behavioral assertion changed.

Full payment-domain suite (all Razorpay/Stripe/terminal-state/resolver/
country-routing/admin/student-checkout files together): **112/112
passing**, 267 assertions.

---

## Phase 10.2C-Fix — Authenticated-Only Booking, Paid Pricing Guard, Payment Method Visibility

### Trigger

Phase 10.2C's `/verify` pass surfaced a data/config bug: every booking
type in the dev database had `is_paid = true` with `price`/`currency`
NULL, and `BookingPriceCalculator` silently treated that as a free
booking — so paid types never showed payment methods. Investigating
that bug led to an explicit product decision from the user: **there is
no guest booking.** All bookings require a logged-in student with a
sufficiently complete account. This phase implements that decision.

### No guest booking rule (Decision)

Guest booking creation and guest payment are both disabled at the
product boundary, not just hidden in the UI:

- `BookingService::GLOBAL_RULES` gained `AuthenticatedAttendeeRule` —
  first in the pipeline, so it fails before any scheduling/pricing
  check runs. It throws when `CreateBookingData::attendeeId` is null,
  which is the same signal `isGuest()` already used — no DTO/pipeline
  redesign needed. This closes the loop for every caller of
  `BookingService::request()`, including the wizard's guest-shaped
  service class (`GuestBookingService::book()` already passed
  `auth()->id()` as `attendeeId`, so an authenticated visitor going
  through `/book` was already attributed correctly — only a genuinely
  anonymous caller now gets rejected).
- `/book` and `/instructors/book` routes gained the standard
  authenticated-frontend middleware stack (`auth`,
  `email.verify.if.required`, `EnsureAccountIsActive`,
  `password.change.required`) — an anonymous visitor is redirected to
  login before the Livewire wizard ever mounts. `/book/manage/{reference}`
  stays public/token-authorized, since it only manages a booking that
  already exists.
- `routes/api.php`'s guest payment routes
  (`POST .../payments/razorpay/initiate`, `.../verify`) were removed
  from the route table entirely — `GuestBookingPaymentController` is
  kept as a class (documented as deliberately unrouted) in case guest
  checkout is reactivated later, but it is not reachable from any
  public route today. `POST /api/v1/guest/bookings` (create) stays
  mounted so it returns a clean `422` (via the same
  `AuthenticatedAttendeeRule`) instead of a `404`, consistent with
  every other "no guest booking" surface. Guest `show`/`cancel`/
  `reschedule` stay reachable via `manage_token`, since those only
  manage a pre-existing booking and never create one or move money.
- Late-arriving webhooks for a guest booking that already has a
  Pending payment attempt (legacy data, or Phase 10.2B's terminal-state
  race) are still processed safely by the existing Option B path
  (`manual_resolution_required` flag, no wallet — there is no student
  account to credit). This is a passive settlement of a payment that
  already happened, not a guest-initiated action, so it is unaffected
  by the routes-removed change above.

### Authenticated student booking guard

`VerifiedActiveStudentRule` (new, second in `GLOBAL_RULES`) checks, for
any non-null `attendeeId`: the user exists, `status === STATUS_ACTIVE`,
and (when `AuthenticationSettings::email_verification_required` is on)
`email_verified_at` is set. Both throw a plain, user-facing
`BookingException` message ("Your account is not active...", "Please
verify your email address before booking a lesson.").

### Profile completion guard — deliberately scoped to payment, not booking creation

The spec asked for profile completion (billing country/currency
resolvable) to gate booking creation itself. That was implemented
first as a third `GLOBAL_RULES` rule and reverted after it masked the
real exception in ~20+ existing tests that assert `expectException(BookingException::class)`
without checking the message — the new rule fired first and those
tests kept "passing" for the wrong reason, which is worse than a
visible failure.

The check now lives one layer up, at the two Livewire payment entry
points (`BookingWizard::initiatePayment()` and
`BookingHistory::initiatePayment()`): if
`auth()->user()->profile->country_id` is null, payment is refused with
"Please complete your profile (country) before paying for this
booking." and a banner, before any provider call is made. Booking
*creation* (including a free/demo booking) never requires a billing
country — only paying for a booking type that has a real price does.
This is a smaller blast radius than a service-layer rule and matches
the natural UX point: a student can hold a reserved slot before
finishing their profile, but must finish it before paying.
`StudentEligibilityRuleTest::test_free_booking_creation_succeeds_without_a_billing_country`
documents this decision as a regression guard.

### Paid booking pricing guard

`BookingPriceCalculator::calculate()` now throws `BookingException`
("This lesson price is not configured yet. Please contact support.")
for any `is_paid = true` type where `price` is null, `price <= 0`, or
`currency` is blank — reversing Phase 8's original design, which
treated a zero/null price as an intentional free booking. A demo/free
type (`is_paid = false`) is unaffected and still resolves to a zero
payable amount.

`BookingTypeForm` (admin): `price` requires `minValue(0.01)` (a paid
type can no longer be saved with a zero price) and both `price` and
`currency` gained `->requiredIfAccepted('is_paid')`, so the invalid
state (`is_paid = true`, price/currency null) can no longer be created
or saved from the admin panel going forward. Existing bad rows in the
dev database are not backfilled by this phase — they now surface as a
clear error the next time they're booked, per the spec's "do not
silently create free booking for paid type" instruction, rather than
being hidden.

### Payment method visibility

No proactive multi-method picker was built — the existing
architecture resolves exactly one provider server-side
(`PaymentProviderResolver`) and reveals it after "Pay now" is clicked,
which the spec's UX flow (section F) already matches. Visibility rules
enforced by that existing flow, confirmed unchanged by this phase:

- Payment section (Pay now button) only renders for a booking that
  `requiresPayment` and is not yet paid/cancelled.
- Razorpay renders when it's the resolved, ready provider.
- The fake provider's "Simulate success/failure" controls only render
  when `$paymentOrder['provider'] === 'fake'` **and**
  `app()->environment(['local', 'testing'])`.
- Stripe shows the existing "coming soon" banner (frontend deferred,
  Phase 10.2C decision — unchanged).
- New this phase: a static "Pay with wallet balance — coming soon."
  line renders next to Pay now on both the wizard's confirmation step
  and `BookingHistory`'s detail modal, whenever a payment is pending —
  wallet is not a resolvable provider (no wallet-to-booking debit
  exists yet), so unlike Stripe/Razorpay it isn't provider-routed; it's
  a static disabled affordance, matching the spec's "show as disabled/
  coming soon" instruction without inventing a payment-method picker
  that doesn't otherwise exist in this UI.

### Out of scope (unchanged from Phase 10.2C)

Wallet-to-booking debit, meeting creation, instructor payout, recurring
wallet deduction, subscriptions/packages, and the Stripe frontend
remain explicitly out of scope for this phase — nothing above changes
any of those boundaries.

### Tests added/updated in Phase 10.2C-Fix

New: `StudentEligibilityRuleTest` (6 tests — active/inactive/suspended/
unverified-email students, billing-country-optional-for-creation
regression guard), `BookingAdminPanelTest` gained 4 admin form
validation tests (paid type requires price+currency, rejects zero
price, valid paid type saves, free type unaffected). Rewritten:
`Guest/GuestBookingTest.php` (guest create denied for demo and paid
types, honeypot/captcha still deny, legacy guest management via
`manage_token` still works against a directly-seeded fixture since the
create endpoint can no longer produce one), `Guest/BookingWizardLivewireTest.php`
(unauthenticated redirect to login, authenticated student completes
the wizard, defense-in-depth check inside the Livewire component
itself). Updated: `BookingFlowHardeningTest`, `CountryAwareProviderResolutionTest`,
`PaymentTerminalStateTest`, `RazorpayCheckoutTest`,
`RazorpayCheckoutLivewireTest`, `StudentCheckoutFrontendTest`,
`BookingPricingCheckoutReadinessTest`, `InstructorDetailTest` — each
either replaced a guest-creates-successfully assumption with a
guest-is-denied assertion, added a billing-country fixture now needed
by the UI-layer payment gate, or (pricing test) replaced the
zero-price-is-free expectation with a rejection expectation.

Full suite: **2067/2067 passing**, 4638 assertions.

---

## Phase 10.2C-Hotfix — Remove Unsafe Student Pay Route & Enforce Verified Provider Payment

### Trigger

The Phase 10.2C-Fix audit (`docs/audits/phase-10.2c-fix-authenticated-booking-audit.md`)
found and live-proved a critical, pre-existing vulnerability:
`StudentBookingController` (a JSON API with zero real Blade/JS
consumers) exposed `POST dashboard/bookings/{booking}/pay`, which
called `BookingPaymentService::markPaid()` using only a client-submitted
`reference` string — no Razorpay/Stripe signature verification of any
kind. `assertReference()`'s only check is a string compare against
`booking->payment_reference`, a value `store()`'s own response handed
back to the same client. `store()` also auto-initiated a real gateway
payment order via `paymentIntentFor()`, bypassing the billing-country/
profile-completeness gate this phase's Livewire flows enforce (that
check only ever lived in `BookingWizard`/`BookingHistory`, never in
the shared service).

### Unsafe legacy student pay route removed

- `routes/web.php`: the `POST /dashboard/bookings/{booking}/pay`
  route registration (`dashboard.bookings.pay`) is removed entirely.
  Confirmed zero other references to that route name anywhere in the
  codebase before removal, so nothing else could break.
- `StudentBookingController::pay()` — deleted. Nothing routes to it;
  it cannot be invoked.
- `StudentBookingController::store()`'s auto-initiate
  (`paymentIntentFor()`) — deleted, along with the now-unused
  `BookingPaymentServiceInterface` constructor dependency. `store()`
  still creates the booking (unchanged) but no longer creates a real
  gateway payment order or returns a `payment` key in its response.

### Payment confirmation requires verified provider flow

`payment_reference` alone can no longer mark any booking paid through
any active route — the only remaining callers of
`BookingPaymentService::markPaid()` are: `BookingHistory::verifyPayment()`
and `BookingWizard`'s equivalent (both call
`RazorpayPaymentProvider::verifyCheckout()` — real HMAC signature
check — first), `BookingPaymentWebhookController` (signature-verified
provider webhook), and `simulateFakePayment()` (re-checks
`app()->environment(['local', 'testing'])` inside the method itself,
local/testing only). No code path settles a payment from client input
alone anymore.

### Profile-completion bypass closed

With `store()`'s auto-initiate removed, there is no remaining active
entry point that creates a payment order without the billing-country
check — the only two places that ever call `BookingPaymentService::initiate()`
for a student are `BookingWizard::initiatePayment()` and
`BookingHistory::initiatePayment()`, both of which check
`auth()->user()->profile->country_id` before calling it.

### Tests

`tests/Feature/Student/StudentBookingTest.php`: `test_payment_placeholder_marks_booking_paid`
(the test that documented the vulnerable behavior as if it were a
feature) replaced with `test_unsafe_pay_route_no_longer_exists`
(asserts `Route::has('dashboard.bookings.pay')` is false, the URL
404s, and the booking/activity log are untouched by the attempt).
`test_student_books_paid_session_with_chosen_teacher` updated to
assert the response no longer carries a `payment` key. Full
payment-domain focused run (student booking, Razorpay checkout +
Livewire, booking payment service, booking history, checkout
frontend, eligibility, country routing, terminal-state, flow
hardening): **113/113 passing**. Full-suite re-run deferred to the
next audit pass, per this hotfix's explicit scope.

---

## Phase 10.2D / 10.2D-Cleanup — Checkout Now Depends on the Pricing Matrix

Everything above this section describes checkout as depending on
`booking_types.price`/`currency` (directly, or as a "legacy fallback"
after Phase 10.2D introduced the matrix). That is no longer accurate.
As of Phase 10.2D-Cleanup:

- `booking_types.price`/`currency` **do not exist** — the columns were
  dropped.
- `BookingPriceCalculator::calculate()` (the single point every
  checkout path — `BookingService::request()`, and transitively every
  Livewire/API caller — goes through before a booking is even created)
  resolves a paid lesson's price exclusively via
  `StudentLessonPriceResolver`. No fallback exists.
- A paid booking with no matching, active `StudentLessonPrice` row is
  rejected at booking-creation time, before checkout/payment is ever
  reached — the "This lesson price is not configured yet" message is
  unchanged, but its cause is now always a missing/misconfigured
  pricing-matrix row, never a missing `booking_types.price`.
- Everything downstream of booking creation — `BookingPaymentService`,
  `RazorpayPaymentProvider`/`StripePaymentProvider`,
  `BookingPaymentWebhookController`, the Livewire checkout flow — is
  **unchanged**. They all operate on `bookings.price`/`currency` (the
  point-in-time snapshot), which is populated the same way regardless
  of whether the value came from the matrix or (previously) the legacy
  column — this phase changed *where the number comes from*, not how
  payment settlement works.

See `docs/architecture/phase-10.2d-student-pricing-matrix.md` for the
full pricing-matrix design and the Phase 10.2D-Cleanup section for
exactly what was removed.

---

## Phase 10.2E — Student Checkout Browser/AJAX Verification

### Browser automation availability

No Laravel Dusk, Playwright, Cypress, or Puppeteer exists in this
project (`composer.json`/`package.json` checked directly — only
`livewire/livewire`'s built-in test helpers and PHPUnit HTTP tests).
Per this phase's own instruction not to install one, verification used
the strongest available approach: `Livewire::test()` (drives real
component AJAX-equivalent method calls — `initiatePayment()`,
`verifyPayment()`, `simulateFakePayment()` — exactly as a browser's
Livewire request would) plus `assertDispatched()` for browser-event
payload shape, plus direct HTTP tests for routes. **Limitation, stated
plainly**: `Livewire::test()->html()` renders a component's Blade
output but does not reproduce a real page load's `wire:snapshot`
attribute, so "does the rendered Blade text contain X" and "is X ever
present in what a browser receives" are not the same question — see
the finding below, which this exact gap surfaced.

### Finding — Stripe `client_secret` was reaching the public Livewire property (fixed)

`BookingWizard`/`BookingHistory::initiatePayment()` assigned the
**entire** result of `checkoutPayload()` to `$this->paymentOrder`
(a `public array` property) unconditionally, before branching on
provider. For Stripe, `checkoutPayload()` legitimately calls Stripe's
`retrievePaymentIntent()` and returns a live, usable `client_secret` —
correct for an actual Stripe Elements integration, but this app's
Stripe frontend is deliberately deferred (no Elements/Checkout.js
consumes it). Because Livewire hydrates every public property into
the page's snapshot for the *next* request, a real browser would have
exposed that `client_secret` (and `publishable_key`,
`payment_intent_id`) in the DOM/AJAX response, reachable via
view-source — even though the existing
`test_stripe_selected_shows_safe_coming_soon_message_and_leaks_no_secret`
test passed throughout, because `Livewire::test()->html()` doesn't
reproduce snapshot serialization (see limitation above).

**Fix**: both components now only assign the full `checkoutPayload()`
result to `$paymentOrder` for Razorpay (needed by the frontend widget)
and fake (no secrets in its payload). For Stripe, `$paymentOrder` is
set to `['provider' => 'stripe']` only — the "coming soon" banner
still shows, but no live gateway credential is ever exposed to the
client while the Stripe frontend stays deferred. The existing secret
test was strengthened to assert directly against
`$component->get('paymentOrder')` (the component's actual public
state), not just the rendered HTML text, so this class of gap can't
silently reopen.

### Razorpay frontend payload — confirmed public-only

`RazorpayPaymentProvider::checkoutPayload()` returns exactly
`provider`, `order_id`, `key_id` (public key), `amount_minor`,
`currency` — re-confirmed by reading the method fresh. The dispatched
`razorpay-checkout-ready` browser event carries exactly `orderId`,
`keyId`, `amountMinor`, `currency`, `name`, `email` — both fixed by
the `$this->dispatch(...)` call's explicit named arguments (structurally
cannot include more) and now asserted exactly via
`assertDispatched('razorpay-checkout-ready', orderId: ..., keyId: ...,
...)` in `RazorpayCheckoutLivewireTest`. `key_secret`/`webhook_secret`
are never read by either Livewire component at all.

### Fake provider — local/testing only, re-confirmed

`simulateFakePayment()` re-checks `app()->environment(['local',
'testing'])` inside the method body on both components (not just
Blade visibility) — re-confirmed by reading both methods fresh.
`test_simulate_fake_payment_is_a_no_op_outside_local_or_testing`
simulates a production environment via `$this->app->detectEnvironment(...)`
and confirms the booking stays unpaid — this is a real environment
override, not a mocked check.

### Stripe frontend — remains deferred, confirmed correct choice

No Stripe.js/Elements was built this phase (none was in scope, and no
bug required one). The "coming soon" banner is the only Stripe-facing
UI; backend Stripe tests (`StripeCheckoutTest`) are untouched and
remain the source of truth for the Stripe payment lifecycle.

### Payment method visibility — re-confirmed, one gap closed

Pay Now: visible for the student's own unpaid paid booking with a
resolvable matrix price; absent for free/demo, already-paid, cancelled,
**and expired-then-released** bookings (this last case had no
dedicated test before this phase — added). A booking without a
matching `StudentLessonPrice` never reaches a payable state at all —
creation itself is rejected with a banner shown through the actual
`BookingWizard::submit()` Livewire flow (previously only proven at the
service layer, not through the UI entry point — added this phase).
Wallet shows a static "coming soon" note (Phase 10.2C-Fix, unchanged).
No provider/gateway metadata (`provider_order_id`, `provider_payment_id`,
raw `BookingPayment.metadata`) is ever rendered to a student — the
only `BookingPayment` read from student-facing code is a boolean
existence check for the wallet-credit banner.

### No wallet debit, no meeting creation from checkout code, no guest checkout

Unchanged and re-confirmed: no wallet-debit code path exists in either
Livewire component; the guest payment routes remain unrouted
(`routes/api.php`, Phase 10.2C-Fix/Hotfix, untouched this phase).

**Phase 11 update**: a verified payment success (webhook-driven
`BookingPaymentService::markPaid()` → `BookingService::confirm()`) may
now trigger meeting creation — but only through the `BookingConfirmed`
event → `CreateMeetingOnBookingConfirmed` listener →
`BookingMeetingService`, never from checkout/webhook code directly (no
`meeting_*` field is written inline in `BookingPaymentService`,
`BookingPaymentWebhookController`, or either Livewire component).
Frontend success alone (`verifyCheckout()`'s signature check, before
`markPaid()` is ever called) still cannot create a meeting — see
`docs/architecture/meetings.md`. Option
B's late-terminal-payment path (`handleLateTerminalPayment()`) never
calls `confirm()` and therefore never dispatches `BookingConfirmed` —
it still cannot create a meeting either.

**Revision** (Manual + Google Meet phase): meeting data now lives in a
dedicated `booking_meetings` table (not `bookings.meeting_status`,
which was dropped) with two working providers, `ManualMeetingProvider`
and `GoogleCalendarMeetProvider` — no fake provider was built. The
trigger point and every guarantee above are unchanged; see
`docs/architecture/meetings.md` for the
current design.

### Tests added in Phase 10.2E

`RazorpayCheckoutLivewireTest` (+3): wizard-submit pricing-error
banner, exact `razorpay-checkout-ready` event payload assertion,
Livewire-level forged-signature rejection (`verifyPayment()` with a
fabricated signature leaves the booking unpaid — proving frontend
success alone can never mark a booking paid, through the actual
component a browser calls, not the provider class directly).
`StudentCheckoutFrontendTest` (+2, plus one strengthened): expired
booking hides Pay Now, no provider/gateway metadata rendered, and the
Stripe secret test now asserts against component state directly.
