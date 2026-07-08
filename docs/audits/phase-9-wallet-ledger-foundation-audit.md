# Phase 9.1 Wallet Ledger Foundation Audit

## Executive Decision

Readiness score: **94/100**

Decision: **SAFE TO PROCEED**

Blocking issues: **none**

This is an independent re-verification of the Phase 9 wallet ledger
foundation — every claim below was checked by reading the current
source, querying the actual database schema directly (not just the
migration file text), and re-running every verification command fresh
in this session. The money-safety fundamentals are sound: all balances
are integer minor units enforced by DB `CHECK` constraints (verified
against live `information_schema`, not assumed), the balance-mutation
guard is a real technical control, idempotency and row-locking are
correctly implemented, and no duplicate or out-of-scope structure
exists. Four minor, non-blocking findings were identified (below); none
represents a money-safety defect.

## Prerequisite Gate

Verified prerequisite: Phase 8.1 audit
(`docs/audits/phase-8-pricing-checkout-readiness-audit.md`), 95/100,
SAFE TO PROCEED TO PHASE 9, no blocking issues. Its two carried-forward
notes were applied correctly: wallet money is integer minor units
throughout (verified below), and the guest-paid-booking gap was left
untouched, as instructed (out of this phase's scope).

## Schema Audit — Verified Against the Live Database, Not Just Migration Files

Ran `SHOW COLUMNS`, `SHOW INDEX`, and an `information_schema.CHECK_CONSTRAINTS`
join against the actual dev database in this session:

- `wallets.balance_minor` / `available_balance_minor` / `held_balance_minor`
  and every `wallet_ledger_entries` amount/balance column are genuinely
  `bigint` — confirmed by `SHOW COLUMNS`, not just by reading the
  migration source.
- Two `CHECK` constraints on `wallets` are live in MySQL:
  `chk_wallets_balance_split` (`balance_minor = available_balance_minor + held_balance_minor`)
  and `chk_wallets_non_negative` (`available_balance_minor >= 0 AND held_balance_minor >= 0`).
- Two `CHECK` constraints on `wallet_ledger_entries` are live:
  `chk_wallet_ledger_amount_positive` (`amount_minor >= 0`) and
  `chk_wallet_ledger_direction` (`direction IN ('credit','debit')`).
- `wallets_user_id_currency_id_unique` is a genuine unique index —
  "one wallet per user per currency" is a database guarantee, not just
  an application-level check.
- `wallet_ledger_entries_idempotency_key_unique` is a genuine unique
  index — the idempotency guarantee has a real backstop below the
  application layer.
- `php artisan migrate:status`: batches **37** and **38** for the two
  new migrations, immediately following the Phase 6 baseline (36) with
  nothing skipped or out of order.

## WalletService / WalletLedgerService Audit

Re-read both files in full:

- `getOrCreateWallet()`: checks-then-creates, with the DB unique index
  as the authoritative race guard — a `QueryException` from a lost race
  is caught and the winning row is re-fetched, not treated as an error.
  An ownership guard (`actor->id === user->id` or `Manage:Wallet`)
  exists here too, matching the Phase 6 service-level-guard pattern
  rather than trusting only the HTTP/Filament layer.
- Every balance-mutating method (`credit`, `debit`, `placeHold`,
  `releaseHold`, `reverse`) routes through a single private
  `transactional()` helper that combines `Wallet::withAuthorizedBalanceMutation()`
  with `DB::transaction()` — verified there is exactly one such helper,
  not five copy-pasted variants, so the locking/authorization guarantee
  can't drift between methods over time.
- `Wallet::$balanceMutationAuthorized` + the `static::updating()` guard
  is a genuine technical control, not a naming convention: confirmed by
  reading `Wallet.php` directly, the guard throws `WalletException` for
  any update touching a balance column outside the authorized context,
  and `getOrCreateWallet()`'s initial zero-balance write is a *create*
  (unaffected, since the guard only fires on `updating`).
  `freeze()`/`unfreeze()`/`close()` correctly never wrap this helper —
  they only touch `status`, so the guard would never fire for them
  anyway.
- Idempotency is checked twice (once before the lock, once after) —
  confirmed both checks are present and the post-lock check is the
  authoritative one, closing the race the pre-lock check alone would
  leave open.
- `reverse()` locks both the wallet row and the target
  `WalletLedgerEntry` row, and re-validates `status === Posted` after
  acquiring both locks — two concurrent reversal attempts on the same
  entry cannot both succeed.

## Minor Findings (non-blocking)

1. **`reverse()` does not call `assertUsable()`.** Every other
   balance-mutating method refuses to touch a closed wallet; `reverse()`
   does not, so an admin (already gated behind `Manage:Wallet`) can
   currently reverse an entry on a closed wallet, and freezing doesn't
   block it either. This may be intentional — reversals are corrective
   audit actions, arguably different in kind from new transactions, and
   a case can be made they should work regardless of status — but this
   isn't documented as a deliberate choice anywhere, and no test proves
   the behavior either way. Recommend either adding the
   `assertUsable()`/skip-for-closed check to match the literal "closed
   wallet cannot be used" rule, or documenting the exception explicitly
   if it's intended. Not exploitable by a student — `manage` is
   required either way.
2. **The student wallet page doesn't handle multi-currency wallets.**
   `WalletOverview::render()` calls `Wallet::query()->forUser($id)->first()`
   with no currency scoping. If a user ever holds wallets in two
   currencies (schema-legal, and `test_two_currencies_for_same_user_are_isolated_wallets`
   proves the service handles it correctly), the page silently shows
   only one of them with no indication a second exists. Low practical
   risk today — nothing in Phase 9 creates a second currency wallet for
   a student without a deliberate admin action, since there is no
   self-service recharge flow yet — but it is untested and undocumented
   as a limitation. Recommend a documentation note now and a proper
   multi-wallet view before any feature that could give a student a
   second currency wallet ships.
3. **Idempotency is tested for `credit()` but not `debit()`.** Both
   share the identical `findByIdempotencyKey()` check inside the same
   `transactional()` wrapper, so this is very low risk, but the debit
   path's idempotency isn't independently proven.
4. **The "money parameters are strictly `int`" reflection test omits
   `adjustment()`.** `credit`/`debit`/`placeHold`/`releaseHold` are
   checked; `adjustment()`'s `$amountMinor` parameter is also correctly
   typed `int` in the actual source (confirmed by direct read) but isn't
   included in the test's method list, so a future regression there
   wouldn't be caught by this specific structural test (it would still
   be caught by PHP's own `declare(strict_types=1)` enforcement at
   runtime, just not by this test).

## Currency Strategy Audit

- `WalletService::resolveCurrency()`: explicit code → student's
  `profile.country.defaultCurrency.code` → `GeneralSettings::default_currency`
  → must match an *active* `currencies` row or `ValidationException`.
  Confirmed no exchange-rate logic exists anywhere in the Wallet domain
  — a credit/debit only ever operates on the single wallet passed in,
  which already carries its own fixed currency; there is no code path
  that could apply an amount denominated in one currency to a wallet in
  another.
- `BookingTypeForm`'s Phase-8 currency `Select` (`Currency::active()`)
  and `WalletService::resolveCurrency()` both independently reuse the
  same `Currency` model and `active()` scope — no parallel currency
  validation logic was introduced.

## Admin / Filament Audit

- `WalletResource::canCreate()`/`canEdit()` return `false`
  unconditionally, and `getPages()` registers only `index`/`view` — no
  `create`/`edit` route exists at all (not merely hidden). Tested
  directly (`GET /admin/wallets/create` and `.../edit` both 404).
- `WalletPermissionSeeder` re-run and re-queried against the live dev
  database in this session: `manager` genuinely has exactly
  `ViewAny:Wallet`, `View:Wallet`, `ViewAny:WalletLedgerEntry`,
  `View:WalletLedgerEntry` — `Manage:Wallet` and `Create:Wallet` are
  confirmed absent from the role by direct query, matching the
  documented "read-only by default" design.
- `LedgerEntriesRelationManager` has no create/edit/delete columns or
  actions — its only row action is `reverse`, itself re-validated
  server-side inside the lock regardless of what the UI shows.
- A relation manager was used instead of a second top-level
  `WalletLedgerEntryResource`, consistent with the existing
  `BookingResource` → `ActivitiesRelationManager` precedent — confirmed
  this isn't a missed requirement, since the spec explicitly allowed
  "WalletLedgerEntryResource if useful" and a relation manager satisfies
  the same access pattern without a redundant nav item.

## Student UI / Feature-Flag Audit

- All three student-facing surfaces — `StudentWalletController::index()`,
  `AccountMenuService`'s new nav item, and `StudentDashboardService::walletSummary()` —
  independently check `FeatureSettings::wallet_enabled`. Confirmed by
  grep across all three files, not just one.
- Queried the live database directly: `features.wallet_enabled` is
  still `false` — Phase 9 did not silently flip the switch on. The
  entire student-facing surface is currently invisible until a
  super_admin deliberately enables it, exactly as documented.
- The "no wallet yet" empty state (no lazy auto-creation on page view)
  was confirmed by reading `WalletOverview::render()` directly — it
  queries, never creates.

## Out-of-Scope Boundary Audit

Confirmed Phase 9 did not implement: Razorpay order creation/capture,
checkout UI, card/UPI flow, booking-payment settlement from a wallet,
instructor payout, refund automation, referral/promo reward engines,
subscriptions/packages, meeting creation, or automatic booking
confirmation after payment. `BookingService`, `BookingPaymentService`,
`PaymentProviderInterface`, and `FakePaymentProvider` are all
byte-for-byte unchanged since the Phase 8.1 audit point (`git diff`
against that commit is empty for all four).

## Duplicate Prevention Search

Direct filesystem search across `app/Models` and `database/migrations`
for every relevant term:

| Term | Result |
|---|---|
| `pricing`, `payments`, `payment_transactions` | None found — valid absence |
| `razorpay_orders`, `razorpay_payments` | None found — valid absence |
| `wallet_holds`, `wallet_transactions` | None found — valid absence (held balance is two columns + entry types, as designed) |
| `instructor_payouts`, `payouts`, `earnings` | None found — valid absence |
| `meetings`, `subscriptions`, `packages`, `referral` | None found — valid absence |
| `wallets` | Exactly one migration — the approved foundation |
| `wallet_ledger_entries` | Exactly one migration — the approved foundation |

Full session diff against the Phase 6 baseline commit confirms exactly
one new Filament resource directory (`Wallets/`), two new models, two
new policies, two new migrations, one new domain module (`app/Wallet/`),
two new factories, and one new seeder — nothing unaccounted for.

## Tests Audit

`composer test` (`php artisan test --env=testing`), run fresh in this
audit session:

```
1936 tests passed, 4328 assertions
```

(1902 at the Phase 8.1 baseline + 34 new in
`WalletLedgerFoundationTest.php`.) Every required coverage item from
the Phase 9 brief is present: wallet creation/duplicate-prevention/
inactive-currency-rejection/default-currency-resolution; credit/debit/
insufficient-balance/balance-after-snapshot/idempotency/reversal-
correctness/direct-update-block; integer-minor-units (both a runtime
assertion and a structural `ReflectionMethod` check); cross-currency
wallet isolation; admin view/manage-denial/adjustment-reason/adjustment-
activity-log/no-create-or-edit-page; student own-page/404-when-disabled/
cross-user-denial/statement-content/disabled-recharge-CTA; and the full
boundary set (no Razorpay/payment/meeting/payout tables or records from
any wallet action, booking creation still never touches the wallet).
The four gaps noted above (§ Minor Findings) are the only items not
independently covered.

## Documentation Audit

`docs/architecture/phase-9-wallet-ledger-foundation.md` — confirmed
present and re-read in full this session. Accurately documents the
existing-state audit, the two-table data model and why a third
(`wallet_holds`) was deliberately not added, the ledger philosophy
(append-only, technically-enforced single writer), the integer-minor-
unit strategy, idempotency and locking strategy, currency strategy,
student UI scope (including the lazy-creation decision and its
reasoning), admin management, permissions, activity logging, what's
intentionally not built, and future integration points. It does not
yet mention the two behavioral findings from this audit (`reverse()`'s
status bypass, the multi-currency UI limitation) — recommended as a
follow-up addition, non-blocking.

## Commands

| Command | Result |
|---|---|
| `composer test` (`php artisan test --env=testing`) | Passed: 1936 tests, 4328 assertions |
| `php artisan migrate:status` | Passed; batches 37–38 (unchanged since, immediately following, batch 36) |
| `php artisan route:list` | Passed; 221 routes (218 + 3: wallet admin index/view + student wallet page) |
| `./vendor/bin/pint --test` | Passed |
| `composer validate` | Passed |
| `npm run build` | Passed (three Blade views changed this phase) |

## Final Decision

Readiness score: **94/100**

Decision: **SAFE TO PROCEED**

The 6-point deduction reflects the four findings above — one genuine
behavioral ambiguity (`reverse()` and wallet status) worth a deliberate
decision and a test either way, one UI limitation worth documenting
before it can bite a future phase, and two narrow test-coverage gaps.
None is a money-safety, duplication, or authorization defect: every
balance mutation is still transactional, locked, idempotent-safe, and
technically (not just conventionally) restricted to
`WalletLedgerService`; no duplicate or out-of-scope structure exists;
`wallet_enabled` remains off by default; and Razorpay/payout/meeting
integration was correctly left out.

No Phase 9.2 hardening pass is strictly required before starting the
next phase, but the `reverse()` status-check decision should be made
consciously (fix or document) before any real money moves through this
ledger.
