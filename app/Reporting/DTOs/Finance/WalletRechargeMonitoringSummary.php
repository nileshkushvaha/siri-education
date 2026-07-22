<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Finance;

/**
 * Current-state (never period-scoped) counts for the recharge
 * monitoring summary cards — every non-terminal/exception attempt
 * regardless of when it was created, mirroring
 * WalletFinancialSummaryData's own "as-of-now" liability cards.
 */
final readonly class WalletRechargeMonitoringSummary
{
    public function __construct(
        public int $providerCreated,
        public int $awaitingConfirmation,
        public int $capturedCreditPending,
        public int $capturedCreditFailed,
        public int $succeeded,
        public int $providerTerminalFailures,
        public int $stale,
        public string $generatedAtIso,
    ) {}
}
