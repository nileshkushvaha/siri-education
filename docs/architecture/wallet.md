# Wallet Ledger

## Data model

Two tables, under a dedicated `app/Wallet/` domain module (mirrors `app/Booking/`'s Enums/Services/Exceptions structure), both UUID-keyed:

- **`wallets`** — one row per (user, currency). `balance_minor = available_balance_minor + held_balance_minor`, and both parts `>= 0`, are enforced by DB `CHECK` constraints, not just application code. `status` (`active`/`frozen`/`closed`) — `closed` is the terminal state instead of soft-deleting, because a wallet with ledger history must never disappear (a soft-delete-and-recreate cycle can't stay correct against a unique `(user_id, currency_id)` index in MySQL).
- **`wallet_ledger_entries`** — append-only. `amount_minor` is an unsigned magnitude; `direction` (`credit`/`debit`) carries the sign. Every row snapshots `balance_after_minor`/`available_after_minor`/`held_after_minor` at post time, so the full balance history is reconstructable from the ledger alone. `idempotency_key` is DB-unique. There is no separate `wallet_holds` table — held balance is tracked as two columns on `wallets` plus `booking_hold`/`booking_hold_release` ledger entry types.

## Ledger philosophy

`WalletLedgerService` is the only writer of any `*_minor` column, enforced technically, not just by convention: `Wallet` has a `static::updating()` guard — any Eloquent update touching `balance_minor`/`available_balance_minor`/`held_balance_minor` outside `Wallet::withAuthorizedBalanceMutation()` throws immediately. Every balance-mutating method (`credit`, `debit`, `placeHold`, `releaseHold`, `reverse`) runs through a shared `transactional()` helper combining this authorization lock with `DB::transaction()` — there is exactly one path balance changes can take. A direct `Wallet::update(['balance_minor' => ...])` from anywhere else fails loudly instead of silently drifting the ledger out of sync. Covered by `WalletLedgerFoundationTest::test_wallet_balance_cannot_be_changed_without_ledger_service`.

A **reversal never mutates history**: it posts a new offsetting entry (opposite direction, same amount) and only changes the original row's `status`/`reversed_at`. The original row's `amount_minor` and `direction` are provably unchanged after a reversal (tested directly). Reversal is allowed regardless of wallet status — a reversal is an administrative correction, not a new transaction, and it's often exactly a frozen/closed wallet's history that needs correcting (`test_reversal_succeeds_on_frozen_wallet`, `test_reversal_succeeds_on_closed_wallet`).

## Integer minor-unit money

Every money field (`wallets.balance_minor`/`available_balance_minor`/`held_balance_minor`, `wallet_ledger_entries.amount_minor`/`balance_after_minor`/`available_after_minor`/`held_after_minor`) is a `bigInteger`, cast `'integer'` on the Eloquent models. `WalletLedgerService`'s public methods declare `int $amountMinor` — never `float`, never `mixed` — checked directly in tests via `ReflectionMethod` so a future edit that weakens the type signature fails CI, not just review. `WalletMoneyFormatter` is the single place a minor-unit integer becomes a display string (using `Currency::$minor_units`), and is never used for storage or arithmetic.

## Idempotency

`credit()`/`debit()` accept an optional `idempotencyKey`, checked twice: once before acquiring the wallet's row lock (fast path), and once again immediately after the lock is acquired (authoritative, race-safe). If a matching entry already exists either time, the existing entry is returned instead of creating a duplicate — the standard retry-safe idempotent-API shape, not an exception, so a caller retrying after a timeout can never double-apply it. The DB's `unique(idempotency_key)` index is the final backstop.

## Transaction / locking strategy

Every balance-mutating method: `DB::transaction()` wraps a `Wallet::query()->whereKey($id)->lockForUpdate()->firstOrFail()` — the same lock-then-recheck pattern the booking engine uses (`BookingRepository::withInstructorLock()`). `reverse()` additionally locks the target `WalletLedgerEntry` row itself before re-validating its status, so two concurrent reversal attempts on the same entry cannot both succeed.

## Currency

`WalletService::resolveCurrency()`: explicit currency code (if passed) → the user's `profile.country.defaultCurrency.code` → `GeneralSettings::default_currency`. The resolved code must match an **active** row in `currencies` or a `ValidationException` is thrown. No exchange-rate engine exists: a user may hold one wallet per currency (tested: two currencies for the same user produce two isolated wallets, and a credit to one never touches the other's balance), but nothing converts between them. The student wallet page currently assumes a single currency per student rather than offering a currency selector (see `WalletOverview`'s docblock) — revisit if/when a student legitimately needs more than one currency wallet.

## Student wallet UI

`GET /dashboard/wallet` (`StudentWalletController`) — `abort_unless(FeatureSettings::wallet_enabled, 404)` at the top, so the whole surface is invisible whenever the module is off (`FeatureSettings::$wallet_enabled` defaults to `false` — enabling it is a product decision). The page never creates a wallet just by being viewed: a student with no wallet sees a plain "No wallet yet" empty state, not an auto-provisioned zero-balance row — there is no recharge flow yet to justify creating a real financial record on a passive page view. The "Recharge" button is rendered disabled with a "Coming soon" label — no route, no JavaScript, no provider call exists behind it yet. The nav item ("Wallet", under the student sidebar) is gated by the same feature flag.

`WalletSettings` (recharge min/max, low-balance threshold) is seeded but not yet enforced anywhere — there is no recharge path to enforce it against yet.

## Admin wallet management

`WalletResource` has **no Create and no Edit page** — `canCreate()`/`canEdit()` return `false` unconditionally, and `getPages()` only registers `index`/`view`, so `/admin/wallets/create` and `/admin/wallets/{id}/edit` 404 (tested directly), matching `BookingResource`'s "no Create page by design" pattern. Every mutation is a header action on `ViewWallet` (freeze/unfreeze/close/admin-adjustment), each wrapping the corresponding `WalletService`/`WalletLedgerService` call in a try/catch that turns `WalletException`/`AuthorizationException` into a Filament notification. `LedgerEntriesRelationManager` is a read-only table (no create/edit/delete columns) with a single `reverse` row action; reversing an already-reversed or pending entry is impossible because `reverse()` itself re-validates `status === Posted` under the lock.

A relation manager is used instead of a second top-level `WalletLedgerEntryResource` — consistent with how `BookingResource` exposes its timeline via a relation manager rather than a separate resource, and it avoids an extra nav item for data that only makes sense in the context of one wallet.

## Permissions

`WalletPolicy`/`WalletLedgerEntryPolicy` (Shield-style, mirroring `BookingPolicy`): a user may always `view` their own wallet/entries; every other ability requires the `Manage:Wallet` permission (freeze/unfreeze/close/admin-adjustment/reversal) or `View:Wallet`/`ViewAny:Wallet` (read access). `WalletPermissionSeeder` grants `manager` **read-only** access by default — `Manage:Wallet` and `Create:Wallet` are deliberately not granted to any role out of the box; a super_admin must consciously grant `Manage:Wallet` to specific managers. `super_admin` bypasses everything via the app-wide `Gate::before()`. `getOrCreateWallet()` itself also checks: an actor may only create a wallet for themselves, or for another user if they hold `Manage:Wallet` — the same service-level ownership-guard pattern used elsewhere, rather than trusting only the Filament/HTTP layer.

## Activity logging

Every state change logs through `AuditTrailService` (never `activity()` directly): wallet creation via `logUser()`; freeze/unfreeze/close/admin-adjustment/reversal via `logOverride()` (reason mandatory, `is_override: true`); every credit/debit via `logUser()` with `entry_type`/`amount_minor`/`balance_after_minor` in the properties. `Wallet`'s own `LogsActivity` trait deliberately only watches `user_id`/`currency_code`/`status` — not the balance columns — since the ledger service's explicit audit calls are the richer, authoritative record of *why* a balance moved; a generic before/after diff on every ledger post would just duplicate that as noise. No payment-provider payload or unnecessary personal data is logged from the wallet domain itself.

## Integration points for future work

- **Real-money recharge (e.g. Razorpay)**: `WalletLedgerService::credit()` with `entry_type = RechargeConfirmed` and an `idempotencyKey` derived from the payment gateway's own reference is the intended landing spot for a webhook handler — the idempotency mechanism already exists to make that safe against webhook retries.
- **Booking payment from wallet**: `placeHold()`/`releaseHold()`/`debit(..., WalletLedgerEntryType::BookingPayment, sourceType: 'booking', sourceId: $booking->id)` already model the hold-then-settle flow a real integration would need; `BookingService` doesn't currently call into wallet.
- **Refunds to wallet**: `BookingPaymentService::recordRefund()` is the natural caller of `credit(..., WalletLedgerEntryType::Refund, ...)`.
- **Referral/promotional credits**: `WalletLedgerEntryType::ReferralCredit`/`PromotionalCredit` already exist in the enum — a future engine only needs to call `WalletLedgerService::credit()`.
- **Instructor earnings**: kept separate — `wallets.user_id` is not role-restricted at the DB level (an instructor wallet can reuse the same table), but no payout/earnings concept is wired to it, and the current UI is student-only.

## Tests

`tests/Feature/Wallet/WalletLedgerFoundationTest.php`: wallet creation (active-currency creation, duplicate prevention, inactive-currency rejection, default-currency resolution), ledger mechanics (credit, debit, insufficient-balance rejection, balance-after snapshotting, idempotency for both credit and debit, reversal correctness and non-mutation of history, the direct-update guard), currency/minor-units (integer typing verified via both runtime assertions and `ReflectionMethod` on the money parameters — including `adjustment()` — cross-currency wallet isolation), admin (permitted view, non-permitted manage denial, adjustment reason requirement, adjustment activity logging, no create/edit page), student UI (own-wallet page, 404 when disabled, cross-user policy denial, statement content, disabled recharge CTA), boundary (no payment/meeting/payout tables or records created by any wallet action, booking creation never touches the wallet), and frozen/closed wallet rules (frozen blocks debit but not credit; closed blocks both).
