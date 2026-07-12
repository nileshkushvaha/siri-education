# Financial Provider Activation Handoff (Phase 16D)

The canonical, always-current status of the two real-money provider
integrations — RazorpayX (instructor payouts) and Stripe (student
collection). Both are **code-complete and account-unverified**. This
document is the single source of truth for what remains before either
may be activated; it supersedes the "Deferred" sections of the
individual phase docs where they now disagree with it.

This is a documentation/handoff phase (Phase 16D) — no provider
functionality, credentials, routes, tables, or business rules were
added or changed to produce it.

## Status

```text
RazorpayX code readiness: Confirmed
RazorpayX account readiness: Unverified
RazorpayX Test Mode readiness: Blocked
RazorpayX Live Mode readiness: Unverified

Stripe code readiness: Confirmed
Stripe account readiness: Unverified
Stripe Test Mode readiness: Blocked
Stripe Live Mode readiness: Unverified

Production financial activation: Not ready
```

"Blocked" means the required external prerequisites (below) do not
exist yet in this environment — it is not a statement about code
quality or a failed verification attempt. Neither provider has ever
been exercised against a real account, real credentials, or a real
webhook delivery. Every test in both codebases uses a mocked or
network-free fake gateway client (`RazorpayXConcurrencyFakeClient`,
`StripeConcurrencyFakeClient`, Mockery mocks of
`RazorpayGatewayClient`/`StripeGatewayClient`) — never `Http::fake()`
against real endpoints, never a real API call.

## Where the code lives

- RazorpayX payout adapter: `docs/phase-16b-razorpayx-india-inr-payout-adapter.md`
- Stripe collection backend, frontend, and reconciliation: built across
  the Phase 16A.1 routing audit (`docs/payment-collection-and-payout-provider-routing.md`)
  and Phase 16C/16C.1 (no dedicated phase doc exists for those — this
  document is their canonical status record)
- Shared payout execution/reconciliation foundation: `docs/financial-domain-architecture.md`

## RazorpayX resume prerequisites

Before Phase 16B.1 (controlled RazorpayX Test-Mode audit) can run:

- Approved RazorpayX account
- Test Mode access
- Test API credentials (key ID + key secret)
- Test source account
- Fixed outbound staging IP
- RazorpayX IP allowlisting for that fixed IP
- HTTPS staging webhook endpoint
- Test webhook secret
- Test balance in the RazorpayX test account (to fund test payouts)
- Dedicated staging database
- Running queue worker
- Running scheduler (`schedule:run` on a real cron, not just `schedule:list`)

## Stripe resume prerequisites

Before Phase 16C.1 (controlled Stripe Test-Mode audit) can run:

- Stripe Test Mode account
- Test secret key
- Test publishable key
- Test webhook endpoint secret
- HTTPS staging environment
- Dedicated staging database
- Running queue worker
- Running scheduler
- Browser-based testing capability for the Stripe Payment Element (a
  real browser session — the mocked test suite cannot substitute for
  this)

Confirmed missing as of this document's writing: HTTPS staging
environment, dedicated disposable database, Stripe Test Mode account
and all associated credentials, public webhook URL. (`APP_ENV=local`,
`APP_URL=http://127.0.0.1:8000` in the current environment.)

## Resume phases

```text
RazorpayX:
Resume Phase 16B.1 — Controlled RazorpayX Test-Mode Audit

Stripe:
Resume Phase 16C.1 — Controlled Stripe Test-Mode Audit
```

Do not skip either audit phase once prerequisites exist — both are
designed to prove the code against a real account with synthetic
data before any production credential is ever entered.

## Activation restrictions

These hold regardless of which provider's audit runs first, and remain
true after either audit completes — activation of one does not relax
any of them for the other:

- RazorpayX is restricted to India/India/INR bank payouts. No other
  country or currency is supported by this adapter.
- Stripe international collection remains disabled
  (`stripe_enabled = false`, `payment_collection_rollout_scope =
  india_razorpay_only`) until verified.
- Razorpay remains the current, only production-proven India/INR
  collection route.
- The student collection provider never determines the instructor
  payout provider — the two domains resolve independently
  (`PaymentProviderResolver` vs. `InstructorPayoutProviderResolver`,
  structurally unrelated interfaces — architecture-tested).
- No currency conversion exists anywhere in either domain
  (architecture-tested).
- No manual mark-paid action exists anywhere in the admin panel
  (architecture-tested) — only a signed webhook or an authenticated
  provider status fetch may settle a payment.
- Wallet-first cancellation refunds remain authoritative
  (`refundToWallet()` is the default path for every automatic
  cancellation).
- Provider refunds remain a separately-permissioned exception
  (`refundViaProvider()`, requires actor + mandatory reason).
- Unknown provider outcomes must reconcile before any retry or
  fallback — never an automatic same-instant fallback to a different
  provider after an uncertain acceptance (the safe-fallback rule:
  fallback is only ever permitted *before* a request may have been
  accepted by the primary provider).

## Documentation audit

Reviewed `docs/financial-domain-architecture.md`,
`docs/payment-collection-and-payout-provider-routing.md`,
`docs/phase-16a-payout-execution-reconciliation-foundation.md`, and
`docs/phase-16b-razorpayx-india-inr-payout-adapter.md` for claims of
"production ready," "live verified," "sandbox verified," "test mode
verified," "account confirmed," or "international payouts supported."
None were found — the existing docs were already consistently and
correctly hedged (e.g. phase-16b's own explicit "Do not treat this
document as 'production ready' merely because the mocked test suite
passes" caveat).

One accuracy correction was made, in the opposite direction (stale
under-claiming, not over-claiming): `financial-domain-architecture.md`
§15 previously listed Stripe's frontend integration and the
collection-side reconciliation subsystem as still-deferred future
work — both were actually built in Phase 16C. That section has been
updated to reflect current code state and point here. Historical phase
reports (`docs/payment-collection-and-payout-provider-routing.md`) were
not rewritten — a superseded notice was added to its §25 instead, per
the instruction to preserve historical phase reports as written.

## Final settings (confirmed unchanged by this phase)

```text
earnings_enabled = false
withdrawals_enabled = false
periodic_compensation_enabled = false
payout_execution_enabled = false

payout_provider = fake
payout_rollout_scope = india_inr_only
razorpayx_enabled = false

payment_collection_rollout_scope = india_razorpay_only
stripe_enabled = false
```

`booking_payment_reconciliation_enabled = true` remains enabled —
detection/status-verification only (`BookingPaymentReconciliationService::reconcileDue()`
polls provider status and applies the same idempotent
`BookingPaymentService::applyProviderStatus()` path a webhook uses; it
performs no provider call for a provider that isn't configured/enabled,
and creates no charge, refund, wallet credit, or booking on its own —
every financial effect it can produce is one a signed webhook could
also have produced).
