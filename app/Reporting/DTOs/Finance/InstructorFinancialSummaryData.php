<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Finance;

/**
 * Instructor financial summary. Requires
 * `ViewInstructorCompensationReports` (never implied by general
 * finance access). Financial dictionary (SRS §5):
 *
 * - An EARNING is the platform's compensation obligation for an
 *   eligible lesson (`earning_amount_minor` — never the student-facing
 *   booking price).
 * - A SETTLEMENT batch is an internal grouping of earnings; marking it
 *   Paid is a manual admin action, not an external transfer.
 * - A WITHDRAWAL is the instructor's request; a PAYOUT attempt is the
 *   external provider operation. Approved ≠ paid; a created payout
 *   attempt ≠ a paid withdrawal; provider success is never inferred
 *   from a local processing state. Each stage is reported separately.
 *
 * Date semantics: `earningsCreated*` = period events by `created_at`;
 * `earningLiabilityByStatusCurrency` = CURRENT-STATE amounts by
 * current status; settlements/withdrawals/payouts count by their own
 * lifecycle timestamps as labeled.
 *
 * @param  array<string, int>  $earningsCreatedAmountByCurrency
 * @param  array<string, array<string, int>>  $earningLiabilityByStatusCurrency  status => currency => minor units (current-state)
 * @param  array<string, int>  $unallocatedReleasableByCurrency  Releasable earnings with no settlement batch
 * @param  array<string, int>  $settlementsByStatus  status => count
 * @param  array<string, int>  $settlementAmountByCurrency  total_amount_minor of non-cancelled batches
 * @param  array<string, int>  $withdrawalsByStatus  status => count
 * @param  array<string, int>  $withdrawalRequestedAmountByCurrency
 * @param  array<string, int>  $withdrawalPaidAmountByCurrency
 * @param  array<string, int>  $payoutAttemptsByStatus  status => count
 * @param  array<string, int>  $demoConversionIncentiveAmountByCurrency  Awards created in the period, by currency
 */
final readonly class InstructorFinancialSummaryData
{
    public function __construct(
        public int $earningsCreatedCount,
        public array $earningsCreatedAmountByCurrency,
        public array $earningLiabilityByStatusCurrency,
        public array $unallocatedReleasableByCurrency,
        public array $settlementsByStatus,
        public array $settlementAmountByCurrency,
        public int $settlementAllocationMismatchCount,
        public array $withdrawalsByStatus,
        public array $withdrawalRequestedAmountByCurrency,
        public array $withdrawalPaidAmountByCurrency,
        public array $payoutAttemptsByStatus,
        public int $openPayoutReconciliationIssues,
        public int $demoConversionIncentiveAwardsCount = 0,
        public array $demoConversionIncentiveAmountByCurrency = [],
    ) {}
}
