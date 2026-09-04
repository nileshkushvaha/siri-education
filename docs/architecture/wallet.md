# Wallet Ledger

## Data model

Three tables, under a dedicated `app/Wallet/` domain module (mirrors `app/Booking/`'s Enums/Services/Exceptions structure), all UUID-keyed:

- **`wallets`** — one row per (user, currency). `balance_minor = available_balance_minor + held_balance_minor`, and both parts `>= 0`, are enforced by DB `CHECK` constraints, not just application code. `status` (`active`/`frozen`/`closed`) — `closed` is the terminal state instead of soft-deleting, because a wallet with ledger history must never disappear (a soft-delete-and-recreate cycle can't stay correct against a unique `(user_id, currency_id)` index in MySQL).
- **`wallet_recharges`** — one row per student intent to add money. Carries `wallet_id`, `user_id`, `amount_minor`, `currency_code`, `reference` (`WRCH-…`, unique) and the **credit** lifecycle (`status`, `failure_code`, `failure_reason`, `succeeded_at`, `failed_at`). It holds **no** `provider`, `provider_order_id` or `provider_payment_id`: external payment identity belongs to `payments` (`payable_type = 'wallet_recharge'`), and holding a second copy meant two records of one charge that could disagree. See the `move_wallet_recharge_provider_identity_to_payments` migration.
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

`WalletService::resolveCurrency()`: explicit currency code (if passed) → the user's `profile.country.defaultCurrency.code` → `GeneralSettings::default_currency`. The resolved code must match an **active** row in `currencies` or a `ValidationException` is thrown. No exchange-rate engine exists: a user may hold one wallet per currency (tested: two currencies for the same user produce two isolated wallets, and a credit to one never touches the other's balance), but nothing converts between them. Per SRS §13.7 the wallet currency follows the student's billing country and cross-currency wallet operations are not supported in Version 1 — the multi-currency schema exists so a student who *moves* market keeps their old balance intact and in its original currency, not so a student can choose a currency. The student wallet page therefore offers no currency selector.

## Recharge limits

Per currency, not platform-wide: `currencies.minimum_recharge_minor` / `maximum_recharge_minor` / `recharge_multiple_minor` / `low_balance_threshold_minor`, integer minor units in that currency's own exponent (SRS §13.12 / §13.16), edited together on **Settings → Wallet** (`WalletSettingsPage` → `WalletCurrencyLimitService`, audited as `wallet_limits_updated`) and enforced by `WalletRechargeAmountPolicy` (minimum, maximum, and "amount must be a whole multiple of the step" — seeded as 10 major units for every currency). NULL means unconfigured — no floor beyond `amount > 0`, no ceiling beyond the provider's technical limits — and is deliberately not the same as 0.

This replaced two platform-wide floats (`wallet.minimum_recharge_amount` / `maximum_recharge_amount`) that the service re-expressed in each wallet's own minor units, so one configured `100` meant ₹100 in India *and* $100 in the United States. With no exchange rate anywhere in the application, a single scalar cannot express a limit meaningful in more than one currency; the old shape was unsound rather than merely under-configured. Only the three minimums SRS §13.12 states (INR 500, USD 10, GBP 10) are seeded, and no maximum is seeded anywhere.

## Recharge payment architecture

A wallet recharge is a `Payable`, exactly like a package purchase or a booking payment obligation. It owns **no provider identity at all**.

```
WalletRecharge (Payable)
  → PaymentCheckoutService → Payment → Razorpay
  → signed webhook  (shared signature service, PURPOSE_WALLET scope)
  → PaymentWebhookEventParser  (one parser, no wallet dialect)
  → PaymentService::findByProviderReference → payable_type guard
  → WalletRechargeSettlementService
  → WalletLedgerService::credit()
```

**Ownership split.** `Payment` owns `provider`, `provider_order_id`, `provider_payment_id`, `paid_at`, `last_synced_at` and the payment lifecycle. `WalletRecharge` owns `wallet_id`, `amount_minor`, `currency_code`, `reference` and the **credit** lifecycle. Amount and currency are duplicated onto the recharge deliberately: they are the domain snapshot settlement validates the provider's reported figures against, so a payment that disagrees with the recharge is detectable at all.

`WalletRechargeService` holds no gateway client, no provider secret, and no provider name in any conditional — it answers only *who may recharge, for how much, in which currency*. Before the cutover it drove `RazorpayGatewayClient`/`StripeGatewayClient` directly and stored provider ids on `wallet_recharges`, so one external charge was described by two independent records that could disagree about whether money had arrived.

The wallet keeps its own webhook **route** (`/api/webhooks/wallets/recharges/{provider}`), matching the house convention that each payable domain owns an endpoint with its own secret scope — a leaked recharge secret must not become authority to settle lessons. What is shared is everything worth sharing: the signature service, the parser, the gateway clients, and the ledger. `/webhooks/payments/generic/{gateway}` is deliberately inert and settles nothing.

## Recharge collection gate

Wallet recharge is external money collection, so **whether it may happen at all is not a wallet decision**. `WalletRechargeService` delegates wholesale to `PaymentCollectionEligibilityService` — the same gate booking collection passes through — asked with the `wallet_recharge` transaction type (`WalletRechargeServiceInterface::TRANSACTION_TYPE`).

That single call covers `payments_enabled`, the collection rollout scope, `Country.status === 'active'` (the canonical market gate), provider routing/configuration, the provider's approved billing currencies — which for Razorpay is where the international attestation `razorpay_international_enabled` + `razorpay_international_currencies` is enforced — and provider health. There is therefore **no wallet-specific country allowlist and no wallet-specific currency allowlist anywhere**, recharge cannot drift from booking collection, and NZD/SAR stay blocked for wallets exactly as long as they stay blocked for bookings. Every check runs *before* any money state exists: a refused recharge creates no `WalletRecharge`, no `Payment`, and reaches no provider.

`LaunchMarketWalletRechargeTest` pins this across all nine launch markets, mirroring `LaunchMarketPaymentTest`.

## Callback vs. webhook

- **`PaymentCallbackVerifier` (browser callback) is non-authoritative.** It verifies the `order_id|payment_id` HMAC, resolves the attempt from the **payable plus order id** — which is what stops a valid callback for someone else's order attaching itself here — records `provider_payment_id` on the `Payment`, and returns. It never settles, never credits, never notifies, never invoices.
- **`WalletRechargeSettlementService::settle()` is authoritative** and is the only path that reaches `WalletLedgerService::credit()`.

Two independent reasons the callback must not settle: it is browser-supplied and replayable, and Checkout.js fires on **authorization, not capture** — an authorized-but-uncaptured payment is money SIRI does not have. It is generic rather than wallet-specific because the wallet previously carried its own copy of the HMAC check, so there were two implementations of "is this callback real" that could drift.

### Instant confirmation on return from checkout

The callback not being *authoritative* does not mean the student waits for the webhook. `WalletOverview::verifyWalletRecharge()` runs the signature check and then, in the same request, calls `WalletRechargeReconciliationService::reconcileOne()` — the exact server-to-server confirmation the scheduled sweep performs. Razorpay is asked for the order; if it reports `paid` with the recorded amount and currency, `settle()` credits the wallet before the response is sent, and the student sees the new balance and a "Payment received" banner as the popup closes.

If the provider has not caught up (capture still in flight), nothing is credited. The component sets `pendingRazorpayPaymentId`, the view renders a `wire:poll.3s` poller, and each `pollWalletRechargeStatus()` tick re-reads the record and, at most every `PROVIDER_RECHECK_SECONDS`, asks the provider again through the same path. A webhook landing mid-poll is a replay for the poller: one credit. After `MAX_RECHARGE_POLLS` ticks (~2 minutes) the page stops asking and tells the student the credit will arrive automatically and not to pay again; the signed webhook and the ten-minute sweep remain behind it. Stripe reuses the same poller with the same provider re-check. Closing Checkout.js without paying calls `razorpayCheckoutDismissed()`, which only explains that nothing was charged.

The invariant is unchanged: the browser never proves payment, the provider does, and `settle()` is still the only path to a credit. What changed is *when* the provider is asked — at the moment the student is looking, instead of ten minutes later.

Because that lookup now sits on a page the student is watching, `RazorpaySdkClient::fetchOrder()` bypasses the SDK (which hardcodes a 60s timeout) and calls the order endpoint through Laravel's HTTP client with `FETCH_ORDER_TIMEOUT_SECONDS` (8s). A slow gateway surfaces as `GatewayRequestException` → "unreachable, not unpaid" → the confirming banner and another poll, never a minute-long hang.

## Two-phase settlement, and why it differs from packages

`PackagePurchaseSettlementService` writes everything in one transaction. Wallet settlement cannot, because crediting a wallet has a **real, persistent** business failure that package activation does not: the destination wallet may be frozen or closed.

```
phase 1 (one txn)   validate → Payment = Paid, Recharge = CreditPending
phase 2 (one txn)   WalletLedgerService::credit() → Recharge = Succeeded
```

If phase 2 is refused, the `Payment` **stays Paid** — the money genuinely was collected, and pretending otherwise would be a lie about money SIRI holds — while the recharge becomes `CreditFailed`: durable, operator-visible via `PaymentReconciliationIssue`, and retryable through `retryCredit()` with **no provider call and no second `Payment`**. Rolling back instead would leave the attempt looking unpaid while the provider held real money, and the provider would retry forever against a wallet that will still be frozen. A credit failure is answered `200`, not `500`: the provider has nothing left to do.

## Reconciliation

`WalletRechargeReconciliationService` owns no provider integration. It sweeps the recharge slice of the `payments` ledger, asks the shared `PaymentAttemptVerifier`, and hands the answer to the same settlement service the webhook uses — so the two can never disagree about what "paid" means. It survives as a thin domain orchestrator for the one recovery no generic sweep would look for: retrying **credits** for recharges whose `Payment` is already `Paid`.

`PaymentAttemptVerifier` now carries the **provider's** reported amount and currency. It previously rebuilt the event from the attempt's own values, on the stated reasoning that echoing the provider back would make settlement's checks self-confirming — that reasoning is inverted, and it meant reconciliation compared each row with itself and the mismatch guards could never fire. Wallet settlement additionally fails closed when a success event carries no amount or currency at all.

## Student wallet UI

`GET /dashboard/wallet` (`StudentWalletController`) — `abort_unless(FeatureSettings::wallet_enabled, 404)` at the top, so the whole surface is invisible whenever the module is off (`FeatureSettings::$wallet_enabled` defaults to `false` — enabling it is a product decision). The page never creates a wallet just by being viewed: a student with no wallet sees a plain "No wallet yet" empty state, not an auto-provisioned zero-balance row. A wallet is created only as a direct effect of the student's own recharge attempt. Recharge amount limits are shown only when the student's currency actually has one configured. The nav item ("Wallet", under the student sidebar) is gated by the same feature flag.

## Admin wallet management

`WalletResource` has **no Create and no Edit page** — `canCreate()`/`canEdit()` return `false` unconditionally, and `getPages()` only registers `index`/`view`, so `/admin/wallets/create` and `/admin/wallets/{id}/edit` 404 (tested directly), matching `BookingResource`'s "no Create page by design" pattern. Every mutation is a header action on `ViewWallet` (freeze/unfreeze/close/admin-adjustment), each wrapping the corresponding `WalletService`/`WalletLedgerService` call in a try/catch that turns `WalletException`/`AuthorizationException` into a Filament notification. `LedgerEntriesRelationManager` is a read-only table (no create/edit/delete columns) with a single `reverse` row action; reversing an already-reversed or pending entry is impossible because `reverse()` itself re-validates `status === Posted` under the lock.

A relation manager is used instead of a second top-level `WalletLedgerEntryResource` — consistent with how `BookingResource` exposes its timeline via a relation manager rather than a separate resource, and it avoids an extra nav item for data that only makes sense in the context of one wallet.

## Permissions

`WalletPolicy`/`WalletLedgerEntryPolicy` (Shield-style, mirroring `BookingPolicy`): a user may always `view` their own wallet/entries; every other ability requires the `Manage:Wallet` permission (freeze/unfreeze/close/admin-adjustment/reversal) or `View:Wallet`/`ViewAny:Wallet` (read access). `WalletPermissionSeeder` grants `manager` **read-only** access by default — `Manage:Wallet` and `Create:Wallet` are deliberately not granted to any role out of the box; a super_admin must consciously grant `Manage:Wallet` to specific managers. `super_admin` bypasses everything via the app-wide `Gate::before()`. `getOrCreateWallet()` itself also checks: an actor may only create a wallet for themselves, or for another user if they hold `Manage:Wallet` — the same service-level ownership-guard pattern used elsewhere, rather than trusting only the Filament/HTTP layer.

## Activity logging

Every state change logs through `AuditTrailService` (never `activity()` directly): wallet creation via `logUser()`; freeze/unfreeze/close/admin-adjustment/reversal via `logOverride()` (reason mandatory, `is_override: true`); every credit/debit via `logUser()` with `entry_type`/`amount_minor`/`balance_after_minor` in the properties. `Wallet`'s own `LogsActivity` trait deliberately only watches `user_id`/`currency_code`/`status` — not the balance columns — since the ledger service's explicit audit calls are the richer, authoritative record of *why* a balance moved; a generic before/after diff on every ledger post would just duplicate that as noise. No payment-provider payload or unnecessary personal data is logged from the wallet domain itself.

## Wallet spending

SRS-approved wallet spend is **lesson bookings only**. `BookingPaymentService::payWithWallet()` locks the booking, re-validates every precondition against the locked row, requires a wallet whose currency *already* matches the booking's (never converting, never creating one in the booking's currency), debits, and finalizes — all in one transaction, so a debit cannot persist without the booking finalizing or vice versa. No gateway call occurs.

Package purchase via wallet is **not** implemented, deliberately: SRS §13.1 lists "future Subscription or Package modules" as integrations the wallet is being *prepared* for, and no chapter authorizes wallet-funded package purchase. That stays deferred rather than assumed.

## Student-facing credit notifications

A wallet credit the student did not initiate must explain itself, or money simply appears in their balance. `SendWalletNotifications` covers all three sources on the `notifications` queue, each claimed through `NotificationIdempotencyGuard`:

| Credit source | Event | Notification |
|---|---|---|
| Recharge settled by the provider | `WalletRechargeSucceeded` | `WalletRechargeSucceededNotification` |
| Lesson-outcome refund (instructor no-show, technical failure, admin exception) | `LessonRefundCompleted` | `LessonRefundCreditedNotification` |
| Promotional campaign credit | `PromotionalCreditIssued` | `PromotionalCreditIssuedNotification` |

A *student-initiated cancellation* refund is deliberately absent from this table — `BookingCancelledNotification` already states its frozen refund outcome, so notifying again from the ledger would double-message one action. The lesson-outcome refund is the opposite case: it is executed later, often by an admin days after the lesson, and was previously silent.

The refund notification's idempotency key is scoped to disposition id **and** `version`, matching the ledger's own key — an admin override that legitimately re-resolves a disposition into a second distinct refund therefore notifies again, while a redelivered event for the same refund does not.

## Integration points for future work

- **Referral/promotional credits**: `WalletLedgerEntryType::ReferralCredit`/`PromotionalCredit` already exist in the enum — a future engine only needs to call `WalletLedgerService::credit()`.
- **Recurring lesson auto-deduction** (SRS §13.14/§13.15): `WalletSettings::$recurring_deduction_hours_before_lesson` is seeded but has no consumer yet.
- **Low-balance alert** (SRS §13.16): per currency (`currencies.low_balance_threshold_minor`, Settings → Wallet). Students see an in-app warning on the wallet page and the dashboard wallet tile when their available balance is below it; the wallet financial report counts such wallets. No email/push notification is sent yet — that would be the next step, via the Activity Log pipeline.
- **Instructor earnings**: kept separate — `wallets.user_id` is not role-restricted at the DB level (an instructor wallet can reuse the same table), but no payout/earnings concept is wired to it, and the current UI is student-only.

## Tests

`tests/Feature/Wallet/WalletLedgerFoundationTest.php`: wallet creation (active-currency creation, duplicate prevention, inactive-currency rejection, default-currency resolution), ledger mechanics (credit, debit, insufficient-balance rejection, balance-after snapshotting, idempotency for both credit and debit, reversal correctness and non-mutation of history, the direct-update guard), currency/minor-units (integer typing verified via both runtime assertions and `ReflectionMethod` on the money parameters — including `adjustment()` — cross-currency wallet isolation), admin (permitted view, non-permitted manage denial, adjustment reason requirement, adjustment activity logging, no create/edit page), student UI (own-wallet page, 404 when disabled, cross-user policy denial, statement content, disabled recharge CTA), boundary (no payment/meeting/payout tables or records created by any wallet action, booking creation never touches the wallet), and frozen/closed wallet rules (frozen blocks debit but not credit; closed blocks both).
