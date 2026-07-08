# Phase 9 Wallet Ledger Foundation

## Decision

Phase 9.0 audited the codebase for any existing wallet implementation
before writing anything. None existed as working code, but forward-
looking scaffolding did: `WalletSettings` (recharge min/max, low-balance
threshold — already seeded), `FeatureSettings::$wallet_enabled` (the
single on/off switch, seeded `false`), an admin settings page
(`PlatformFoundationSettingsPage`) with a "Wallet" section whose own
description says "wallet ledger logic ships later," and an abstract
`WalletNotification` base class already wired into `EmailLogService`'s
category mapping. Phase 9.1 builds exactly the ledger logic that
scaffolding anticipated, reusing all of it rather than duplicating any
of it.

## Prerequisite

`docs/audits/phase-8-pricing-checkout-readiness-audit.md`: 95/100, SAFE
TO PROCEED TO PHASE 9. Two carried-forward notes were applied: all
wallet money is stored as `int` minor units (never `float`), and the
guest-paid-booking gap from Phase 8 was left untouched (out of scope for
this phase, still non-blocking — see `docs/audits/phase-8-pricing-checkout-readiness-audit.md` §12).

## Existing Wallet/Payment State (Phase 9.0 audit)

- No `wallets`/ledger table, model, or service existed anywhere.
- `WalletSettings` (group `wallet`) already seeded:
  `minimum_recharge_amount`, `maximum_recharge_amount`,
  `low_balance_threshold`, `recurring_deduction_hours_before_lesson` —
  all pre-existing, untouched by this phase (they configure a future
  recharge flow this phase does not build).
- `FeatureSettings::$wallet_enabled` already seeded `false` — this
  phase's entire student-facing surface (page, nav item, dashboard
  tile) is gated behind it, matching the class's own documented "one
  switch per feature" rule. It stays `false` by default; enabling it is
  a product decision, not something this phase flips on its own.
- `BookingPaymentService` (Phase 7/8, untouched) already has
  `refund()`/`recordRefund()` — the natural future hook for
  "refund to wallet," not wired up in this phase.
- No subject/instructor pricing or payout fields existed anywhere.

## Wallet Data Model

Two new tables, matching the approved design exactly:

- **`wallets`** — one row per (user, currency). `balance_minor =
  available_balance_minor + held_balance_minor` and both parts `>= 0`
  are enforced by DB `CHECK` constraints, not just application code.
  `status` (`active`/`frozen`/`closed`) — `closed` is the terminal
  state instead of soft-deleting, because a wallet with ledger history
  must never actually disappear. No soft-deletes column: adding one
  would need a partial-unique index on `(user_id, currency_id)` to stay
  correct after a delete-and-recreate, which MySQL cannot express
  cleanly — `closed` avoids the problem entirely.
- **`wallet_ledger_entries`** — append-only. `amount_minor` is an
  unsigned magnitude; `direction` (`credit`/`debit`) carries the sign.
  Every row snapshots `balance_after_minor`/`available_after_minor`/
  `held_after_minor` at post time, so the full balance history is
  reconstructable from the ledger alone. `idempotency_key` is
  DB-unique. No `wallet_holds` table was added — held balance is
  tracked as two columns on `wallets` plus `booking_hold`/
  `booking_hold_release` ledger entry types, per the "do not overbuild"
  instruction.

Both use UUID primary keys, matching the `bookings`/`teacher_availability`
convention already established in this codebase, under a new
`app/Wallet/` domain module mirroring `app/Booking/`'s
Enums/Services/Exceptions structure.

## Ledger Philosophy

`WalletLedgerService` is the only writer of any `*_minor` column, full
stop — enforced technically, not just by convention. `Wallet` gained a
`static::updating()` guard: any Eloquent update that touches
`balance_minor`/`available_balance_minor`/`held_balance_minor` outside
`Wallet::withAuthorizedBalanceMutation()` throws immediately. Every
balance-mutating method in `WalletLedgerService` (`credit`, `debit`,
`placeHold`, `releaseHold`, `reverse`) runs through a shared
`transactional()` helper that combines this authorization lock with
`DB::transaction()`, so there is exactly one path balance changes can
take. A direct `Wallet::update(['balance_minor' => ...])` from
anywhere else — a future controller, a Filament form, a console
command — fails loudly instead of silently drifting the ledger out of
sync. Covered by
`WalletLedgerFoundationTest::test_wallet_balance_cannot_be_changed_without_ledger_service`.

A **reversal never mutates history**: it posts a new offsetting entry
(opposite direction, same amount) and only changes the original row's
`status`/`reversed_at`. `amount_minor` and `direction` on the original
are provably unchanged after a reversal (tested directly).

## Integer Minor Unit Money Strategy

Every money field — `wallets.balance_minor`/`available_balance_minor`/
`held_balance_minor`, `wallet_ledger_entries.amount_minor`/
`balance_after_minor`/`available_after_minor`/`held_after_minor` — is a
`bigInteger`, cast `'integer'` on the Eloquent models. `WalletLedgerService`'s
public methods declare `int $amountMinor` (not `float`, not `mixed`) —
checked directly in tests via `ReflectionMethod` so a future edit that
weakens the type signature fails CI, not just review. `WalletMoneyFormatter`
is the single place a minor-unit integer becomes a display string (using
`Currency::$minor_units`, which already existed and defaults to 2); it is
never used for storage or arithmetic. This directly applies the Phase
8.1 audit note carried into this phase's brief: `BookingPriceData` uses
`float` (a design note for a future pass, not fixed here since it's out
of this phase's scope), but the wallet ledger — real, mutable money —
uses integers end to end from day one.

## Idempotency Strategy

`credit()`/`debit()` accept an optional `idempotencyKey`. It is checked
twice: once before acquiring the wallet's row lock (fast path for the
common case) and once again immediately after the lock is acquired
(authoritative, race-safe). If a matching entry already exists either
time, the existing entry is returned instead of creating a duplicate —
the standard "retry-safe" idempotent-API shape, not an exception, so a
caller that retries a request after a timeout can never double-apply
it. The DB's `unique(idempotency_key)` index is the final backstop.

## Transaction / Locking Strategy

Every balance-mutating method: `DB::transaction()` wraps a
`Wallet::query()->whereKey($id)->lockForUpdate()->firstOrFail()` —
identical in spirit to `BookingRepository::withHostLock()`'s
lock-then-recheck pattern from the booking engine. `reverse()`
additionally locks the target `WalletLedgerEntry` row itself before
re-validating its status, so two concurrent reversal attempts on the
same entry cannot both succeed.

## Currency Strategy

`WalletService::resolveCurrency()`: explicit currency code (if passed)
→ the user's `profile.country.defaultCurrency.code` → `GeneralSettings::default_currency`.
The resolved code must match an **active** row in `currencies` or a
`ValidationException` is thrown — reusing the Phase 8 Currency
foundation exactly, no new currency table or logic. No exchange-rate
engine exists or was built: a user may hold one wallet per currency
(tested: two currencies for the same user produce two isolated
wallets, and a credit to one never touches the other's balance), but
nothing converts between them. `BookingTypeForm`'s currency `Select`
(Phase 8) already constrains admin input to active currencies the same
way.

## Student Wallet UI Scope

`GET /dashboard/wallet` (`StudentWalletController`) — `abort_unless(FeatureSettings::wallet_enabled, 404)`
at the top, so the whole surface is invisible whenever the module is
off. The page never creates a wallet just by being viewed: a student
with no wallet sees a plain "No wallet yet" empty state, not an
auto-provisioned zero-balance row. This was a deliberate, conservative
reading of "create wallet lazily... only if product approves" — Phase
9 has no working recharge flow to justify creating a real financial
record on a passive page view, so the empty state is the only safe
default until a real trigger (recharge) exists. The "Recharge" button
is rendered disabled with a "Coming soon" label — no route, no
JavaScript, no provider call exists behind it. The nav item ("Wallet",
under the existing student sidebar) is gated by the same feature flag
via a new small `'enabled' => Closure` check added to
`AccountMenuService::resolve()`.

## Admin Wallet Management

`WalletResource` has **no Create and no Edit page** — `canCreate()`/
`canEdit()` return `false` unconditionally, and `getPages()` only
registers `index`/`view`, so `/admin/wallets/create` and
`/admin/wallets/{id}/edit` 404 (tested directly), matching
`BookingResource`'s "no Create page by design" precedent. Every
mutation is a header action on `ViewWallet`
(freeze/unfreeze/close/admin-adjustment), each wrapping the
corresponding `WalletService`/`WalletLedgerService` call in a
try/catch that turns `WalletException`/`AuthorizationException` into a
Filament notification — the same pattern established in Phases 6–8.
`LedgerEntriesRelationManager` is a read-only table (no create/edit/
delete columns) with a single `reverse` row action; reversing an
already-reversed or pending entry is impossible because `reverse()`
itself re-validates `status === Posted` under the lock.

A relation manager was used instead of a second top-level
`WalletLedgerEntryResource` — consistent with how `BookingResource`
already exposes its timeline via `ActivitiesRelationManager` rather
than a separate resource, and it avoids an extra nav item for data that
only ever makes sense in the context of one wallet.

## Permissions

`WalletPolicy`/`WalletLedgerEntryPolicy` (Shield-style, mirroring
`BookingPolicy`): a user may always `view` their own wallet/entries; every
other ability requires the `Manage:Wallet` permission (freeze/unfreeze/
close/admin-adjustment/reversal) or `View:Wallet`/`ViewAny:Wallet` (read
access). `WalletPermissionSeeder` grants `manager` **read-only** access
by default (`ViewAny:Wallet`, `View:Wallet`, `ViewAny:WalletLedgerEntry`,
`View:WalletLedgerEntry`) — `Manage:Wallet` and `Create:Wallet` are
deliberately **not** granted to any role out of the box. A super_admin
must consciously grant `Manage:Wallet` to specific managers via the
existing Roles & Permissions UI. `super_admin` bypasses everything via
the app-wide `Gate::before()`, unchanged. `getOrCreateWallet()` itself
also checks: an actor may only create a wallet for themselves, or for
another user if they hold `Manage:Wallet` — mirroring the Phase 6
service-level ownership-guard pattern rather than trusting only the
Filament/HTTP layer.

## Activity Logging

Every state change logs through `AuditTrailService` (never `activity()`
directly, per project convention): wallet creation via `logUser()`;
freeze/unfreeze/close/admin-adjustment/reversal via `logOverride()`
(reason mandatory, `is_override: true`, independently discoverable
regardless of `log_name`); every credit/debit via `logUser()` with
`entry_type`/`amount_minor`/`balance_after_minor` in the properties.
`Wallet`'s own `LogsActivity` trait deliberately only watches
`user_id`/`currency_code`/`status` — not the balance columns — because
the ledger service's explicit audit calls are the richer, authoritative
record of *why* a balance moved; a generic before/after diff on every
single ledger post would just be noise duplicating that. No payment
provider payload, full raw metadata, or unnecessary personal data is
logged anywhere (there is no payment provider integration yet to log
from).

## What Is Intentionally Not Built

Razorpay order creation/capture, checkout UI, card/UPI flow, booking
payment settlement from a wallet, instructor earnings/payout, refund
automation, referral/promo reward engines, subscriptions/packages,
meeting creation, and automatic booking confirmation after payment. No
migration beyond the two wallet tables was added.

## Future Integration

- **Razorpay**: `WalletLedgerService::credit()` with `entry_type =
  RechargeConfirmed` and an `idempotencyKey` derived from the payment
  gateway's own reference is the intended landing spot for a webhook
  handler — the idempotency mechanism already exists to make that safe
  against webhook retries.
- **Booking payment from wallet**: `placeHold()`/`releaseHold()` /
  `debit(..., WalletLedgerEntryType::BookingPayment, sourceType:
  'booking', sourceId: $booking->id)` already model exactly the
  hold-then-settle flow a real integration would need; `BookingService`
  was not touched this phase.
- **Refunds to wallet**: `BookingPaymentService::recordRefund()` is the
  natural caller of `credit(..., WalletLedgerEntryType::Refund, ...)`
  in a future phase.
- **Referral/promotional credits**: `WalletLedgerEntryType::ReferralCredit`/
  `PromotionalCredit` already exist in the enum; a future engine only
  needs to call `WalletLedgerService::credit()`.
- **Instructor earnings**: deliberately kept separate — `wallets.user_id`
  is not role-restricted at the DB level (an instructor wallet can reuse
  the same table later), but no payout/earnings concept exists yet and
  none was implied by this phase's UI (student-only).

## Tests

`tests/Feature/Wallet/WalletLedgerFoundationTest.php` (34 tests): wallet
creation (active-currency creation, duplicate prevention, inactive-currency
rejection, default-currency resolution), ledger mechanics (credit,
debit, insufficient-balance rejection, balance-after snapshotting,
idempotency, reversal correctness and non-mutation of history, the
direct-update guard), currency/minor-units (integer typing verified via
both runtime assertions and `ReflectionMethod` on the money parameters,
cross-currency wallet isolation), admin (permitted view, non-permitted
manage denial, adjustment reason requirement, adjustment activity
logging, no create/edit page), student UI (own-wallet page, 404 when
disabled, cross-user policy denial, statement content, disabled
recharge CTA), boundary (no Razorpay/payment/meeting/payout tables or
records from any wallet action, booking creation still never touches
the wallet), and frozen/closed wallet rules (frozen blocks debit but
not credit; closed blocks both).

Full regression: `composer test` → **1936 tests passed, 4328 assertions**
(1902 baseline + 34 new), including every Phase 2–8 test file. Five
pre-existing "no wallet table" assertions from Phases 5–8 were updated
to reflect that `wallets`/`wallet_ledger_entries` are now the approved,
in-scope foundation — each was changed to assert zero *records* were
created by that phase's operations instead, which is the actually
meaningful claim.

## Remaining Gaps

- No recharge flow exists yet (by design — this is the ledger
  foundation, not checkout; Phase 10 adds Razorpay checkout for direct
  booking payment, still not wallet recharge).
- `WalletSettings`' recharge min/max/low-balance-threshold fields are
  not yet enforced anywhere (nothing reads them), since there is no
  recharge path to enforce them against.
- The Phase 8 guest-paid-booking gap (documented in that phase's audit)
  is addressed by Phase 10's guest checkout flow — see
  `docs/architecture/phase-10-razorpay-checkout-payment-capture.md`.
- Instructor wallets are schema-compatible but not exposed anywhere —
  intentionally deferred until a payout phase defines the actual
  requirement.

### Phase 9.1 audit findings — resolved in Phase 10

1. **`WalletLedgerService::reverse()` and frozen/closed wallets**:
   decided and documented as intentional — a reversal is an
   administrative correction, not a new transaction, and is allowed
   regardless of wallet status (it is often exactly a frozen/closed
   wallet's history that needs correcting). See the method's docblock.
   Tested: `test_reversal_succeeds_on_frozen_wallet`,
   `test_reversal_succeeds_on_closed_wallet`.
2. **Student wallet page and multiple currencies**: documented as a
   deliberate single-currency assumption (see `WalletOverview`'s
   docblock) rather than building a selector — the platform is
   INR-primary (Phase 10's Razorpay integration is INR-only), so a
   second currency wallet for a student has no realistic path to exist
   yet. Revisit with a currency-aware selector before that changes.
3. **Idempotency test coverage**: `test_debit_idempotency_key_prevents_duplicate_entry`
   added alongside the existing credit test.
4. **Strict-typing reflection coverage**: `adjustment()` added to
   `test_money_parameters_are_strictly_typed_int_not_float`'s checked
   method list.
