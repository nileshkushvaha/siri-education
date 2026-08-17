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
        // `providerCreated` and `awaitingConfirmation` were two separate
        // cards describing how far an external charge had got. That is a
        // fact about the recharge's Payment attempt, not about the
        // wallet, so they collapse into one domain card: the student has
        // asked to add money and the payment has not settled yet.
        public int $awaitingPayment,
        public int $capturedCreditPending,
        public int $capturedCreditFailed,
        public int $succeeded,
        public int $providerTerminalFailures,
        public int $stale,
        public string $generatedAtIso,
    ) {}
}
