# Payment Gateway Production Checklist

Audit-ready checklist for turning on real Razorpay and/or Stripe
traffic. Every item below must be confirmed before `payment_provider`
is switched away from `fake` outside a local/testing environment.
Cross-reference `docs/architecture/phase-10-razorpay-checkout-payment-capture.md`
(Phase 10.1 and 10.2 sections) for the implementation this checklist
gates.

## Current state (as of Phase 10.2E)

- **The checkout UI's public Livewire state was audited for secret
  exposure and one real gap was closed.** `BookingWizard`/`BookingHistory`
  no longer assign Stripe's `checkoutPayload()` (which includes a
  live, usable `client_secret`) to the public `$paymentOrder` property
  — only `['provider' => 'stripe']` is kept while the Stripe frontend
  stays deferred. Razorpay's payload (`order_id`/`key_id`/`amount_minor`/
  `currency`) and the fake provider's payload (booking reference/amount/
  currency only) remain fully public — neither ever carried a secret.
  Verified this holds by asserting against the component's own public
  state (`$component->get('paymentOrder')`), not just rendered HTML
  text — a rendered-HTML-only check cannot detect data serialized into
  Livewire's snapshot for a real page but never explicitly echoed by
  Blade (see `docs/architecture/phase-10-razorpay-checkout-payment-capture.md`,
  Phase 10.2E, for the full explanation).
- **No true browser automation exists in this project** (no Dusk,
  Playwright, Cypress). Phase 10.2E's verification used
  `Livewire::test()` + HTTP tests — the strongest available approach,
  but it does not reproduce a real page's `wire:snapshot` DOM content.
  A manual/real-browser pass (view-source on an actual checkout page)
  remains the only way to fully close that gap — see the checklist
  item below.

## Current state (as of Phase 10.2C-Hotfix)

- **No direct student payment-reference route exists.** The former
  `POST dashboard/bookings/{booking}/pay` (`dashboard.bookings.pay`)
  accepted a client-submitted `payment_reference` with no gateway
  signature verification, letting a student mark their own booking
  paid without paying. It has been removed — `Route::has('dashboard.bookings.pay')`
  is `false` and `StudentBookingController::pay()` no longer exists.
  Before enabling either gateway for real traffic, re-confirm this
  route is still absent (`php artisan route:list --path=dashboard/bookings`
  should list only `index`/`store`/`teachers`/`previous-teachers`/`slots`).
- **Provider verification is mandatory before a booking can become
  `paid`.** The only remaining callers of `BookingPaymentService::markPaid()`
  are: `BookingHistory`/`BookingWizard`'s `verifyPayment()` (calls
  `RazorpayPaymentProvider::verifyCheckout()` — real signature check —
  first, every time), `BookingPaymentWebhookController` (signature-verified
  webhook), and `simulateFakePayment()` (re-checks
  `app()->environment(['local', 'testing'])` inside the method itself).
  No code path accepts a bare `payment_reference` from a student
  request and trusts it. See
  `docs/audits/phase-10.2c-fix-authenticated-booking-audit.md` for how
  this was found and proven exploitable, and the hotfix section of
  `docs/architecture/phase-10-razorpay-checkout-payment-capture.md`
  for the fix itself.
- **There is no guest booking or guest payment.** Booking creation
  requires an authenticated, active student
  (`AuthenticatedAttendeeRule`, `VerifiedActiveStudentRule` in
  `BookingService::GLOBAL_RULES`). `/book` and `/instructors/book` are
  behind auth middleware; `POST /api/v1/guest/bookings/{reference}/payments/razorpay/initiate`
  and `.../verify` are no longer routed at all
  (`GuestBookingPaymentController` is unreachable). Guest
  `show`/`cancel`/`reschedule` (token-authorized management of an
  already-existing booking) still work, for legacy data only.
- **A paid booking type can no longer resolve to a free booking.**
  `BookingPriceCalculator::calculate()` throws for any `is_paid = true`
  type with a null/zero price or missing currency, instead of silently
  treating it as free. The admin `BookingTypeForm` requires
  `price > 0` and a `currency` whenever `is_paid` is on, so this state
  can no longer be newly created from the admin panel — but check for
  pre-existing bad rows below before enabling a real gateway.
- Payment method visibility on the student checkout UI is
  provider-resolved (one active provider per request, shown after
  "Pay now" is clicked), not a proactive multi-method picker. A static
  "Pay with wallet balance — coming soon." note renders next to Pay
  now; wallet is not a resolvable provider and cannot be selected.

## Historical state (as of Phase 10.2C)

- No real Razorpay or Stripe credentials exist in this codebase, this
  repo's `.env`, or any settings row in any environment this work
  touched. `PaymentGatewaySettings.razorpay_enabled` and
  `stripe_enabled` are both `false`; all credential fields are blank;
  `razorpay_config_status`/`stripe_config_status` are both
  `not_configured`.
- `BookingSettings::payment_provider` defaults to `fake`;
  `PaymentGatewaySettings.default_provider` is `null` (unset).
- `PaymentProviderResolver` refuses to resolve `fake` outside
  `local`/`testing`, and refuses everything if `payments_enabled` is
  `false` — both enforced in code, not just convention.
- Razorpay/Stripe calls go through the official `razorpay/razorpay` /
  `stripe/stripe-php` SDKs (isolated behind `RazorpayGatewayClient`/
  `StripeGatewayClient` adapters), not raw HTTP. Laravel Cashier is
  **not** installed and is not part of this integration.
- `countries.payment_routing` (JSON) can override the provider per
  country but is `null`/unset for every country in this environment —
  every checkout currently falls through to `default_provider` →
  `BookingSettings::payment_provider`.
- **Country-aware resolution is now wired into checkout** (Phase
  10.2B): `BookingPaymentService::initiate()`/`checkoutPayload()`
  resolve the student's country (`UserProfile.country_id` → `Country.iso2`)
  and pass it to `PaymentProviderResolver`. Guest bookings have no
  country field at all and always use `default_provider`/legacy
  `BookingSettings::payment_provider`.
- **A late/terminal payment success no longer rejects — Option B**
  (Phase 10.2B): the Phase 10.1/10.2 "reject outright" behavior was
  replaced. A signature/amount/currency-verified success arriving for
  an already-cancelled/expired booking is preserved and credited to the
  student's wallet (`WalletLedgerEntryType::LatePaymentCredit`); guest
  bookings (no wallet) are flagged `manual_resolution_required` on the
  `booking_payments` row instead. Neither path ever confirms the
  booking, clears its reservation, or creates a meeting.
- **Authenticated student checkout frontend is provider-neutral**
  (Phase 10.2C): `BookingWizard`/`BookingHistory` now branch on the
  resolved provider instead of always dispatching a Razorpay-shaped
  event. `BookingPaymentService::initiate()` also gained the terminal-
  booking guard `markPaid()` already had — a cancelled/expired booking
  can no longer have a *new* payment order created against it via
  either UI. Stripe frontend remains a safe "coming soon" message, not
  a real checkout — see the Stripe section below before enabling Stripe
  for real student traffic.

## Before enabling Razorpay

- [ ] Real Razorpay sandbox credentials obtained (`key_id`, `key_secret`)
      and entered via **Admin → Payment Settings → Payment Gateways →
      Razorpay**. Never paste them into `.env`, a migration, a seeder,
      or a commit — they are stored encrypted
      (`Crypt::encryptString`) via `PaymentSettingsPage::saveEncryptedField()`.
- [ ] `razorpay_key_id` matches the real `rzp_test_...`/`rzp_live_...`
      format — `PaymentProviderConfigValidator::isValidRazorpayKeyId()`
      will reject anything else, including copy-paste placeholders.
- [ ] Webhook endpoint registered with Razorpay:
      `POST {APP_URL}/api/webhooks/bookings/payments/razorpay`
      (**not** the generic `/api/webhooks/payments/razorpay` — that is
      the separate, unrelated multi-gateway scaffold that does not
      settle bookings).
- [ ] `razorpay_webhook_secret` set to the value Razorpay shows when
      the webhook is registered (use the "Generate Webhook Secret"
      admin action only for local testing — production must use
      Razorpay's own value).
- [ ] `razorpay_enabled = true` in Payment Gateway settings.
- [ ] Provider selected via **one** of: `Country::payment_routing`
      (per-country override), `PaymentGatewaySettings.default_provider`,
      or `BookingSettings::payment_provider` (checked in that priority
      order by `PaymentProviderResolver` — setting more than one is
      fine, but know which one will actually win).
- [ ] `razorpay_sandbox_mode` reflects reality — leave `true` until
      `key_id`/`key_secret` are swapped to `rzp_live_...` values.
- [ ] Click **Validate Credentials** for Razorpay in the admin UI and
      confirm the tab badge shows **Ready** (not Incomplete/Invalid) —
      this persists `razorpay_config_status` and is a quick sanity
      check before the end-to-end test below.
- [ ] Razorpay checkout script tested end-to-end with real sandbox
      credentials from a **student browser session** (`BookingWizard`
      step 7 and `BookingHistory`'s retry-payment modal), not just the
      backend webhook — confirm `checkout.razorpay.com/v1/checkout.js`
      actually loads and the modal opens with the correct amount/
      currency, then confirm the browser's network tab shows no
      `key_secret`/`webhook_secret` in any request or response.

## Before enabling Stripe

- [ ] Real Stripe test-mode credentials obtained (`publishable_key`,
      `secret_key`) and entered via **Admin → Payment Settings →
      Payment Gateways → Stripe**. Stored encrypted, same as Razorpay.
- [ ] `stripe_secret_key` matches `sk_test_...`/`sk_live_...` and
      `stripe_publishable_key` matches `pk_test_...`/`pk_live_...` —
      `PaymentProviderConfigValidator` rejects anything else.
- [ ] Webhook endpoint registered with Stripe:
      `POST {APP_URL}/api/webhooks/bookings/payments/stripe`.
- [ ] `stripe_webhook_secret` set to the value Stripe's dashboard shows
      for that endpoint (format `whsec_...`).
- [ ] `stripe_enabled = true`.
- [ ] Provider selected via `Country::payment_routing`,
      `PaymentGatewaySettings.default_provider`, or
      `BookingSettings::payment_provider` (same priority order as
      Razorpay above; only one provider resolves per request unless
      country routing is configured to split by country).
- [ ] Click **Validate Credentials** for Stripe in the admin UI and
      confirm the tab badge shows **Ready**.
- [ ] **Frontend Stripe Elements/Checkout UI is still not built as of
      Phase 10.2C** — the student checkout UI deliberately shows a safe
      "coming soon" message and takes no further action when Stripe is
      the resolved provider. Confirm a real frontend integration exists
      and is wired to `checkoutPayload()`'s `client_secret`/
      `publishable_key` before routing real student traffic through
      Stripe — until then, switching `payment_provider`/`default_provider`/
      a country route to `stripe` only blocks student checkout with the
      deferred message, it does not silently fail unsafely, but it also
      collects no real payments.

## Before enabling either gateway — payment-integrity gating (Phase 10.2C-Hotfix)

- [ ] Assert no direct student payment-reference route exists:
      `php artisan route:list --path=dashboard/bookings` lists only
      `index`/`store`/`teachers`/`previous-teachers`/`slots` — no
      `pay` action, and `Route::has('dashboard.bookings.pay')` is
      `false`. If a future change reintroduces a route that calls
      `BookingPaymentService::markPaid()` from a controller, confirm it
      verifies the provider's signature first (see next item) rather
      than trusting a client-submitted reference.
- [ ] Assert provider verification is mandatory before paid status:
      grep the codebase for callers of `BookingPaymentService::markPaid()`
      and confirm every one of them is either (a) preceded by a real
      provider signature check (`RazorpayPaymentProvider::verifyCheckout()`
      or equivalent), (b) a signature-verified webhook controller, or
      (c) `simulateFakePayment()`'s local/testing-only path. Any new
      caller that isn't one of these three is the same class of bug
      the Phase 10.2C-Hotfix audit found and fixed.

## Before enabling either gateway — booking/checkout gating (Phase 10.2C-Fix / 10.2D)

- [ ] **Superseded by Phase 10.2D**: `booking_types.price`/`currency`
      no longer exist — a paid booking type can never carry its own
      price. Instead, every paid booking type has at least one active
      `student_lesson_prices` row covering the country/subject/duration
      combinations students will actually book. Query
      `student_lesson_prices` for the booking types, countries, and
      subjects your rollout targets and confirm coverage — a missing
      row surfaces to the student as "This lesson price is not
      configured yet," the same message as before, now caused by a
      pricing-matrix gap instead of a `booking_types` column.
  - [ ] Pay Now visibility spot-checked end-to-end for at least one
        real paid booking type + country + subject combination — Phase
        10.2E's `Livewire::test()` coverage proves the Blade/AJAX logic
        is correct, but only a populated pricing matrix proves a real
        student can actually reach checkout in production.
- [ ] Guest booking routes confirmed disabled in the target environment:
      `POST /api/v1/guest/bookings/{reference}/payments/razorpay/initiate`
      and `.../verify` return `404`; `POST /api/v1/guest/bookings`
      (create) returns `422`, never `201`.
- [ ] Student profile completion gate tested against a real account:
      a student with no `country_id` on their profile sees "Please
      complete your profile (country) before paying for this booking."
      instead of a checkout modal, on both the wizard's confirmation
      step and `BookingHistory`'s retry-payment modal.
- [ ] Payment method visibility spot-checked in a real browser session,
      not just the test suite: a paid, correctly-priced booking type
      shows "Pay now" and the wallet "coming soon" note; a free/demo
      booking shows neither; a cancelled/expired booking shows neither.
- [ ] Razorpay sandbox credentials validated (see "Before enabling
      Razorpay" above) **before** any real student is allowed through
      the authenticated checkout flow — the auth/profile/pricing gates
      above only stop a student from reaching checkout with bad
      inputs, they do not substitute for the gateway credential check.
- [ ] Razorpay frontend payload checked for secret leakage with real
      sandbox credentials, in an actual browser: open dev tools →
      Network tab, click Pay now, inspect the `initiatePayment`
      Livewire response body and the `razorpay-checkout-ready` event —
      confirm only `order_id`/`key_id`/`amount_minor`/`currency`/
      `name`/`email` appear, never `key_secret` or `webhook_secret`.
      Phase 10.2E proved this structurally (the dispatch call's
      explicit named arguments) and via `assertDispatched()`, but a
      real browser pass is the only way to see what actually leaves
      the server for this specific deployment's configuration.
- [ ] Stripe frontend either implemented and passing its own secret-leakage
      check, or confirmed still deferred: if still deferred, verify
      `$paymentOrder` never carries more than `['provider' => 'stripe']`
      when Stripe resolves (Phase 10.2E fixed a real gap here — Stripe's
      `checkoutPayload()` includes a live, usable `client_secret` that
      was reaching the public Livewire property with no frontend to
      consume it; confirm this fix is still in place before any future
      Stripe frontend work, since a real implementation will need to
      re-introduce `client_secret` deliberately, not accidentally).
- [ ] Profile-completion payment block verified against a real account
      (student with no billing country sees the block, not a checkout
      modal) and terminal-booking payment block verified (a cancelled/
      expired booking's Pay Now stays hidden, and a direct
      `initiatePayment()`/webhook replay against it is rejected).
- [ ] A full browser/Livewire AJAX pass completed against a
      staging-like environment (real login, real `/book` flow, real
      Pay now click) — this phase's automated coverage is
      backend/Livewire-component-level only (confirmed: no Dusk,
      Playwright, or Cypress tooling exists in this project, and none
      was installed per this phase's own instructions); a manual or
      future Playwright/Dusk pass is still required before production
      traffic, per "Backend verification remains source of truth" not
      meaning "browser pass is optional." The Stripe `client_secret`
      finding above is a concrete example of a class of gap
      `Livewire::test()` cannot fully rule out (it doesn't reproduce a
      real page's `wire:snapshot` DOM content) — treat that as the
      standing reason this item stays required, not a one-time fix.

## Shared, before either gateway carries real traffic

- [ ] `payment_provider` is **not** `fake` in the production
      environment's settings row (`PaymentProviderResolver` enforces
      this at runtime, but verify at the settings level too — don't
      rely solely on the runtime guard).
- [ ] A test payment executed successfully end-to-end in sandbox mode
      (booking created → checkout initiated → provider confirms →
      webhook received → booking marked paid → confirmed if
      auto-confirmable).
- [ ] A failed payment tested (declined card / failed UPI) — booking
      stays unpaid, reservation holds for retry, no confirmation, no
      meeting.
- [ ] A duplicate webhook delivery tested (replay the same event twice)
      — second delivery is acknowledged as ignored, no double-charge
      side effect, `booking_payments` row count unchanged.
- [ ] A late webhook against a cancelled/expired-reservation booking
      tested — confirms Option B (Phase 10.2B) credits the student's
      wallet correctly (or flags guest bookings for manual resolution)
      against the real gateway's actual webhook payload shape, not just
      the test suite's synthetic one. Check the resulting wallet
      balance and the `booking_payments` row's "Resolution" section in
      the admin UI, not just the HTTP response.
- [ ] Duplicate delivery of that same late webhook tested — confirms
      the wallet is credited exactly once (`wallet_ledger_entries` row
      count unchanged on the second delivery).
- [ ] If country-based routing is configured for this deployment: a
      test booking from a student profile in each routed country
      confirms the expected provider was actually used (check
      `booking_payments.provider` on the resulting row, not just that
      checkout succeeded — a misconfigured route could silently use
      the wrong gateway for a country if `default_provider` or the
      legacy `BookingSettings::payment_provider` also happens to match).
- [ ] Wallet feature flag (`FeatureSettings::wallet_enabled`) reviewed:
      it only gates the student-facing wallet UI/menu/dashboard widget,
      **not** Option B's automatic wallet credit — a late-success
      credit will post even if the wallet UI is hidden from students.
      If that is not the desired production behavior, decide and
      document a mitigation before enabling a real gateway (this
      phase deliberately did not add a second gate, to avoid the two
      flags drifting out of sync).
- [ ] Application logs checked for secret leakage after a real test
      transaction — grep logs for `key_secret`, `webhook_secret`,
      `client_secret`, `secret_key`; none should appear. (Code-level
      guarantee exists; this step verifies the guarantee held in
      practice for this specific deployment's logging configuration.)
- [ ] Payment settings reviewed by an admin/second person before going
      live — confirm `razorpay_sandbox_mode`/Stripe test-vs-live keys
      match the intended environment; a sandbox flag left `true` in
      production silently routes real money through Razorpay's test
      mode (no charge occurs, but checkout will appear to fail for
      real users).
- [ ] Feature flag / rollout plan reviewed — confirm which
      role/portal/currency/country segment is routed to the new
      gateway first (via `Country::payment_routing` if splitting by
      country), and that the resolved provider can be reverted to
      `fake` (staging) or the previously-working gateway quickly if
      something is wrong.
- [ ] `payments_enabled` confirmed `true` (the platform-wide kill
      switch) and `allowed_providers` either empty or explicitly
      includes the gateway being enabled — both new in Phase 10.2A,
      easy to overlook since their defaults are permissive.
- [ ] Click **Mark Production Checklist Reviewed** in the admin UI
      after completing this document — records `production_ready_at`
      as an audit trail. This does not enable anything by itself.
- [ ] Confirm the fake provider's "Simulate success"/"Simulate failure"
      buttons do **not** render for a production build — they are
      gated by `app()->environment(['local', 'testing'])` both in the
      Blade view and again inside `simulateFakePayment()` itself, but
      visually confirm on a staging/production-like deploy rather than
      relying on the code review alone.
- [ ] Cancelled/expired-reservation bookings confirmed to hide the
      "Pay Now" button in both `BookingWizard` and `BookingHistory` —
      and confirm the backend also rejects a direct/replayed initiate
      attempt (`BookingPaymentService::initiate()`'s terminal-status
      guard, Phase 10.2C) rather than relying on the button being
      hidden.

## What is explicitly still out of scope (do not build ad hoc during rollout)

- Wallet recharge via gateway, wallet-to-booking payment.
- Instructor payouts, subscriptions/packages.
- Meeting link creation tied to payment success.
- Auto-refund-to-original-payment-method for a late webhook against a
  terminal booking — Option B (Phase 10.2B) credits the student's
  wallet instead; pushing money back to the original card/UPI/bank is
  explicitly deferred, not built.
- A guest account-claim flow that lets a guest later register and have
  an admin credit their new wallet from a `manual_resolution_required`
  payment — documented as future work only; guest late-success payments
  currently require a support/admin workflow outside the app.
- Country-based routing **is wired into checkout** (Phase 10.2B) but
  only for the student flow — guest bookings have no country field to
  route by at all (confirmed by direct inspection, not just untested).

## Meeting creation (Phase 11: Manual + Google Meet) — checklist additions

- [ ] A verified payment success creates **exactly one** meeting per
      booking — `BookingMeetingService::createMeeting()` is idempotent
      (checked before and again inside the write transaction, and
      `booking_meetings.booking_id` is unique); a duplicate/replayed
      webhook cannot re-trigger it in the first place, since
      `BookingPaymentService::markPaid()` only re-confirms (and thus
      only re-dispatches `BookingConfirmed`) on the first,
      not-yet-`Paid` delivery.
- [ ] Cancelled/expired/failed/refunded bookings never get a meeting —
      `BookingMeetingService::isEligible()` requires
      `status === Confirmed` plus a payment_status matching the
      booking kind (`Paid` for paid types, `NotRequired` for demo/free).
- [ ] A meeting provider failure (misconfigured credentials, a thrown
      exception mid create/update, or an unregistered provider key)
      never corrupts payment/booking state — it only ever sets
      `booking_meetings.status = failed` with a sanitized
      `failure_reason` and logs via `AuditTrailService`; no rollback,
      no wallet mutation, no booking cancellation.
- [ ] Google configuration tested via the admin "Test Google
      Configuration" action (`GoogleCalendarConfigurationService::check()`)
      before go-live — confirm it reports `ready`, not
      `incomplete`/`invalid`/`not_configured`. This is pure
      settings/format inspection (no network call), so a `ready` status
      does not by itself prove the service account has calendar
      write access — pair it with the sandbox pass below.
- [ ] Manual provider fallback tested: with Google intentionally
      misconfigured, confirm the admin "Create/Update Meeting" action
      can still create a `manual` meeting for the same booking
      (`saveManualMeeting()`), and that it is idempotent (re-submitting
      updates the existing row, never creates a second one).
- [ ] `MeetingSettings::meetings_enabled` and `default_provider`
      confirmed intentional before go-live — `default_provider = manual`
      is a real, working provider now (not an off switch); the
      platform-wide off switch is `meetings_enabled = false`.
- [ ] Before enabling `google_meet_enabled` in production, complete a
      sandbox pass: create a real Google Calendar event end-to-end for
      a real confirmed booking on the configured `google_calendar_id`,
      confirm the Meet join link actually resolves, and confirm no
      credential/token value appears in `booking_meetings.metadata`,
      `failure_reason`, logs, or the Activity Log. `ZoomMeetingProvider`
      remains an unimplemented placeholder (`isConfigured()` hardcoded
      `false`) — this checklist item does not apply until a future
      phase builds it.
