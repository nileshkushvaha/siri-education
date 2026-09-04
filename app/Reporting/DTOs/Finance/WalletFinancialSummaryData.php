<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Finance;

/**
 * Wallet financial summary. Financial dictionary (SRS §5):
 *
 * - `movements` are PERIOD-EVENT ledger aggregates grouped by
 *   (currency, entry type, direction) — a wallet DEBIT is consumption,
 *   never external cash collection; a REFUND credit is an internal
 *   wallet credit, never a gateway refund; referral/promotional/
 *   recharge credits stay distinct types. Rows with status Posted or
 *   Reversed are both included: a reversal writes an explicit
 *   offsetting Posted row (same type, opposite direction) while the
 *   original flips to Reversed, so gross activity and its reversal are
 *   each visible; `reversalCount` counts the flipped originals.
 * - `currentLiabilityByCurrency` is an AS-OF-NOW metric (sum of
 *   guarded `wallets.balance_minor` per currency) — it never changes
 *   with the report period.
 * - `balanceMismatchCount` compares each wallet's stored balance to
 *   its latest ledger `balance_after_minor` — a read-only
 *   reconciliation signal, never repaired from here.
 *
 * All amounts are integer minor units, always paired with a currency;
 * cross-currency totals are structurally impossible (grouped arrays,
 * no grand-total field).
 *
 * @param  list<array{currency: string, entryType: string, direction: string, count: int, amountMinor: int}>  $movements
 * @param  array<string, int>  $currentLiabilityByCurrency  currency code => minor units
 */
final readonly class WalletFinancialSummaryData
{
    public function __construct(
        public array $movements,
        public array $currentLiabilityByCurrency,
        public string $liabilityAsOf,
        public int $walletCount,
        public int $positiveBalanceWallets,
        public int $zeroBalanceWallets,
        public int $lowBalanceWallets,
        public int $reversalCount,
        public int $balanceMismatchCount,
    ) {}
}
