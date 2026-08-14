# Generic Payable / Payment Attempt Foundation (Phases 4B.1–4B.2)

The canonical reference for the wider financial domain remains
[docs/financial-domain-architecture.md](financial-domain-architecture.md),
and for provider routing
[docs/payment-collection-and-payout-provider-routing.md](payment-collection-and-payout-provider-routing.md).
This document records one narrower thing: the **generic
`Payable` → `payments` foundation** introduced so that new paid things
(starting with package purchases in Phase 4B.2) do not each grow their
own bespoke payment-record table, status enum, and state machine.

Phase 4B.1 built the foundation; Phase 4B.2 gave it its first real
consumer, `StudentPackagePurchase`, and package checkout. Settlement is
still deliberately absent: nothing here moves a payment to Paid,
marks a purchase Paid, or activates an entitlement. That is Phase 4B.3.

## 0. The package money lifecycle

```
PackageBenefitRule          admin-defined offer template
        ↓
InstructorPackageProposal   personalized commercial offer
        ↓ admin approves, student accepts
StudentPackagePurchase      the accepted purchase  (pending_payment)
        ↓ 1:N  — it is the Payable
Payment attempts            #1 failed · #2 cancelled · #3 pending
        ↓ verified settlement (Phase 4B.3)
StudentPackageEntitlement   the usable lesson balance
```

Each row in that chain is a separate record on purpose. Collapsing any
two of them loses something real: the offer template is reusable, the
proposal is a negotiation, the purchase is what was agreed, an attempt
is one try at collecting, and the entitlement is a balance that gets
drawn down.

### Purchase failure ≠ payment-attempt failure

This is the distinction the whole design turns on:

```
Purchase  pending_payment
 ├── Payment attempt #1  failed
 ├── Payment attempt #2  cancelled
 └── Payment attempt #3  pending
```

A declined card does not fail the purchase. The student still owes the
same amount for the same accepted proposal and may simply try again, so
`PackagePurchaseStatus` has no `Failed` case at all. A retry is a NEW
attempt against the SAME purchase — never a second purchase, which
`UNIQUE(proposal_id)` forbids outright.

### Entitlement activation is deferred

Accepting an approved proposal used to activate a lesson balance
immediately (Phase 4A). Since Phase 4B.2 it does not: acceptance
creates a `pending_payment` purchase and nothing usable. The balance is
granted only by verified settlement, so a student can no longer obtain
lessons by accepting an offer they have not paid for.

## 1. The transitional architecture (read this first)

There are now **three** collection record paths, deliberately, and two
of them are frozen:

| Path | Record table | Status |
|---|---|---|
| Booking checkout | `booking_payments` | **LEGACY — frozen.** Not migrated, not modified. |
| Wallet recharge | `wallet_recharges` | **LEGACY — frozen.** Not migrated, not modified. |
| Package purchase (`StudentPackagePurchase`) | `payments` | **NEW.** The path all future paid things use. |

This is a transition, not a permanent triplication. The decision (see
§2) was to build the generic abstraction now but apply it **only to new
consumers** — migrating two live, money-carrying tables with settled
history and working webhooks would be a large risk for no user-visible
gain. Whether `booking_payments` and `wallet_recharges` are ever folded
in is a future decision, explicitly out of scope here.

**Rule for new work:** if you are adding a new thing a student pays
for, implement `Payable` and use `PaymentService`. Do not copy
`booking_payments` or `wallet_recharges`.

## 2. Why an abstraction at all — and why not the obvious one

The pre-implementation audit found that wallet recharge is already a
*second* non-booking payment path, and that it bypasses
`PaymentProviderInterface` entirely — it calls
`RazorpayGatewayClient` / `StripeGatewayClient` directly. That single
fact ruled out the intuitive move of widening
`PaymentProviderInterface` to cover packages: doing so would not have
unified wallet, because wallet does not use that interface.

The audit also found that the **expensive and security-sensitive layer
is already shared** by both existing paths:

- `RazorpayGatewayClient` / `StripeGatewayClient` — the SDK layer
- `PaymentWebhookSignatureService` — signature verification

What is duplicated between booking and wallet is *boilerplate*: a
record table, a status enum, a state machine, a webhook controller, a
reconciliation service. So the abstraction introduced here is exactly
that boilerplate layer and nothing else.

**No duplicate SDK or signature layer exists or may be created.** There
is no package-specific Razorpay or Stripe client, and no second webhook
signature verification: package checkout reuses the existing gateway
clients, and Phase 4B.3's settlement will reuse the existing signature
service.

## 3. `Payable` — the contract

`App\Payments\Contracts\Payable` is deliberately tiny:

```php
paymentPayableType(): string   // morph ALIAS, never a FQCN
paymentPayableId(): string
paymentAmountMinor(): int      // integer minor units
paymentCurrencyCode(): string
paymentUserId(): int
paymentReference(): string     // human-facing reference
paymentMetadata(): array       // non-sensitive display context only
```

What it deliberately excludes, and why:

- **Refunds** — a refund is not a property of the thing being bought;
  each domain's refund policy differs (the wallet-first refund policy
  is a booking-domain rule).
- **Webhooks / provider ids / checkout markup** — provider concerns,
  owned by the gateway layer, not by the purchasable thing.
- **Domain lifecycle** — activating an entitlement, confirming a
  booking, crediting a wallet. A `Payable` says what is owed, never
  what happens afterwards.

## 4. `payments` — attempts, not purchases

**One `Payable` has many `Payment` rows.** A `Payment` is a single
*attempt* to collect. A declined card followed by a successful retry is
two rows, and the failed row is never overwritten — retry history is
evidence.

Key schema properties (`2026_10_24_100000_create_payments_table.php`):

- `payable_type` (string 50) + `payable_id` (string 60), **no foreign
  key** — the `wallet_ledger_entries` precedent. A polymorphic FK is
  not expressible; payable existence and ownership are enforced at the
  service boundary instead.
- `amount_minor` unsigned bigint with `CHECK (amount_minor > 0)`, plus
  `currency_code` char(3). Money is always integer minor units, via
  `App\Support\MoneyFormatter`.
- Unique `(provider, provider_order_id)` and
  `(provider, provider_payment_id)` — the same gateway id can never be
  recorded twice, while the same id string may legitimately recur
  across two different providers.
- Unique `idempotency_key` (nullable; NULLs are distinct in MySQL, so
  many attempts may omit it).
- `PreventsHardDeletion` — attempts are historical records.

**Nothing sensitive is stored.** No card numbers, no CVV, no UPI
credentials, no raw webhook signatures, no secret provider payloads.
A test (`PaymentFoundationTest`) asserts the column list contains no
such column, so this stays true as the table evolves.

### `student_package_purchases` holds no gateway state

The purchase table deliberately has no `provider`, `provider_order_id`,
`provider_payment_id`, `idempotency_key`, `failure_code`, or
`failure_message`. All of those describe ONE attempt, and a purchase
has many; storing any of them on the purchase would be wrong the moment
a student retries, and would duplicate state the generic payment layer
already owns.

What it does hold is the accepted commercial snapshot — `reference`,
`amount_minor`, `currency_id`/`currency_code`, `status`, `accepted_at`,
`paid_at` — plus `UNIQUE(proposal_id)` and `CHECK (amount_minor > 0)`.
The snapshot is copied from the approved proposal's `final_price_minor`
and currency and is never re-resolved: an admin override of £300 down
to £275 is what the student pays, even if the pricing matrix changes a
second later. The model enforces this too, refusing any update that
touches the proposal, student, reference, amount, or currency.

### Morph aliases

`payable_type` stores a stable alias (`package_purchase`), never a PHP
FQCN — class names are refactorable, database rows are not. The map
lives in `App\Providers\PaymentServiceProvider::PAYABLE_MORPH_MAP`;
its single entry today is `package_purchase => StudentPackagePurchase`,
declared from `StudentPackagePurchase::PAYABLE_TYPE` so the model and
the map cannot drift apart. `Relation::morphMap()` **merges** by
default, so this coexists with the CMS aliases registered elsewhere; do
not re-register the same aliases in a second provider.

## 5. `PaymentStatus`

`Pending`, `Processing`, `Paid`, `Failed`, `Cancelled`.

- `Processing` exists because both live providers emit it (Stripe
  `payment_intent.processing`, Razorpay `attempted`).
- `Authorized` is **not** included: it is declared in
  `BookingPaymentRecordStatus` but never assigned by any provider — a
  dead state, not worth reproducing.
- `Refunded` is out of scope (see §3).
- `Expired` is **not** a payment status here — gateway-attempt expiry
  is a distinct concern from the package validity of §7, and no
  speculative status is added before something assigns it.

`Paid`, `Failed`, and `Cancelled` are terminal: `isTerminal()` returns
true and `allowedTransitions()` is empty. A settled attempt is never
reopened; a retry is a new row.

## 6. `PaymentService` — the only writer

`App\Payments\Services\PaymentService` is the sole writer of payment
attempts. Nothing else may insert into or update `payments`.

- `startAttempt(Payable, string $provider, ?string $idempotencyKey)` —
  refuses while another attempt for the same payable is still open, and
  validates a positive amount.
- `attemptsFor(Payable)` / `isPaid(Payable)`.
- `transition(Payment, PaymentStatus, array $attributes)` — row-locked,
  enum-guarded, sets `paid_at` / `failed_at`.

`recordProviderOrder()` is the one write that is not a status change:
creating a gateway order does not advance an attempt's lifecycle, so
forcing it through `transition()` would mean inventing a fake
Pending → Pending edge.

Audit entries go through `AuditTrailService::logSystem()`, never raw
`activity()`. Failures raise `App\Payments\Exceptions\PaymentException`
(distinct from `BookingException`).

## 6a. `PaymentCheckoutService` — attempt to gateway order

Turns any `Payable` into a live checkout using the **same** gateway
clients Booking and Wallet already use. There is no package-specific
Razorpay/Stripe client, no second SDK wrapper, and no duplicate webhook
signature verification.

```
{domain} service        who may pay, for what, in which currency
PaymentCheckoutService  attempt <-> provider-order orchestration
PaymentService          attempt record mechanics
*GatewayClient          the external provider API
```

Ordering mirrors `WalletRechargeService::initiate()`: the local attempt
row is written first, then the provider is called. A gateway failure
therefore leaves a durable `Failed` attempt (`failure_code =
provider_order_failed`) rather than an invisible one, and can never
leave a provider order with no local record.

Provider **selection** is not this service's job. The calling domain
service resolves it through the existing `PaymentProviderResolver`
(country routing → default provider → booking setting, plus the
platform kill switch, allowed-provider list, and credential
validation). No package-specific routing policy exists.

### Repeat clicks: resume, don't duplicate

`PaymentService::startAttempt()` refuses to open a second attempt while
one is still open, so the checkout entry point resolves that
deliberately rather than letting a double-clicked Pay button fail:

```
Pay clicked → open attempt exists? → yes → resume it
                                   → no  → new attempt
```

Resuming is safe for every supported provider because **no new
provider-side order is created**: Razorpay's order id is stored on the
attempt, and Stripe's intent is re-fetched for its `client_secret`
(which is deliberately never persisted). An explicit **Cancel Payment
Attempt** also exists, for the case where an open attempt can no longer
be presented at all — cancelling marks the local attempt `Cancelled`
and leaves the purchase `pending_payment`, free to retry.

There is deliberately **no time-based attempt expiry**: no scheduler,
no arbitrary timeout, and no `Expired` status. Explicit resume and
cancel cover the practical cases; real gateway expiry semantics can be
designed later from what the providers actually report.

## 7. Package validity is **not** a payment concept

Phase 4B.1 also adds package expiry. Three expiry concepts exist and
must never be merged:

| # | Concept | Where it lives |
|---|---|---|
| A | **Entitlement usage validity** — how long the student has to *use* purchased lessons | `validity_days` / `expires_at` (this phase) |
| B | **Payment-attempt expiry** — a gateway order/intent going stale | Provider concern; no column, no status |
| C | **Offer / proposal acceptance expiry** — how long an approved proposal stays acceptable | `instructor_package_proposals.expires_at` (pre-existing) |

`validity_days` is concept **A** only.

### Ownership and flow

The **package offer defines the validity**, and only an admin may set
it. It is configured on `PackageBenefitRule` ("Package Validity", in
days) and an instructor can neither set nor override it —
`CreatePackageProposalData` has no validity field at all, which makes
this a structural guarantee rather than a runtime check.

The value is then **snapshotted forward**:

```
PackageBenefitRule.validity_days   (admin-owned source)
  └─ snapshot → InstructorPackageProposal.validity_days
       └─ carried → StudentPackageEntitlement.validity_days
```

Editing the offer later never changes an existing proposal's snapshot;
only new proposals pick up the new value.

`NULL` means **no expiry**. Zero is rejected at both the service
boundary (`PackageException`) and by a DB `CHECK
(validity_days IS NULL OR validity_days > 0)`, precisely so that `0`
can never silently come to mean "unlimited".

### `expires_at` is not computed yet

`student_package_entitlements.expires_at` exists and is nullable, but
nothing writes it and nothing auto-expires in this phase. The absolute
instant is computed only when an entitlement is **activated after
payment**, which is Phase 4B.3.

**Product consequence (intended V1 behaviour):** bonus and paid lessons
share one validity window and therefore expire together. Separate bonus
expiry is not built and should not be added unless specifically
required.

## 8. Tests

- `tests/Feature/Payments/PaymentFoundationTest.php` — 19 tests over
  the generic foundation: morph-alias storage, attempt cardinality and
  retry history, the open-attempt guard, integer minor units, provider
  id and idempotency uniqueness, legal/illegal transitions, absence of
  credential columns, absence of a polymorphic FK, deletion safety.
- `tests/Feature/Package/PackageValidityTest.php` — 13 tests over
  validity ownership, snapshot semantics, NULL handling, invalid-value
  rejection at both layers, and the deliberate absence of any
  auto-expiry.
- `tests/Feature/Package/StudentPackagePurchaseTest.php` — 39 tests over
  purchase creation, the price snapshot, the morph round-trip, checkout,
  retry/resume/cancel, gateway reuse, immutability, and authorization.

The generic foundation is still exercised against
`tests/Support/Payments/FakePayable`, a plain non-Eloquent object —
proof that a payable need not be an Eloquent model at all, which
matches `payments` having no FK to the payable. The real Eloquent
round-trip (`Payment → payable → StudentPackagePurchase`) is covered
separately in the purchase tests.
