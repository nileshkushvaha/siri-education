<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Finance;

/**
 * Phase 18E — refund summary. Version 1 lesson-refund policy is WALLET
 * CREDIT ONLY (verified: the lesson-refund execution action never calls
 * a gateway refund API; gateway refunds exist only in the separate
 * booking-cancellation pipeline). "Decision" = a lesson financial
 * disposition classifying the student outcome; "execution" = the
 * Refund wallet-ledger credit it links to (`refund_ledger_entry_id`,
 * stamped `refund_executed_at`). The two timestamps are distinct and
 * never conflated.
 *
 * @param  array<string, int>  $decisionsByStatus  LessonFinancialDispositionStatus::value => count
 * @param  array<string, int>  $executedAmountByCurrency  currency => minor units
 */
final readonly class RefundSummaryData
{
    public function __construct(
        public int $refundDecisionsInPeriod,
        public array $decisionsByStatus,
        public int $pendingExecution,
        public int $executedCount,
        public array $executedAmountByCurrency,
        public int $manualReviewCount,
    ) {}
}
