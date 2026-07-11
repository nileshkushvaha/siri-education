<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Earnings\Enums\PayoutReconciliationIssueType;
use App\Earnings\Enums\PayoutReconciliationSeverity;
use App\Models\InstructorPayoutAttempt;
use App\Models\InstructorPayoutReconciliationIssue;
use App\Models\User;

interface InstructorPayoutReconciliationServiceInterface
{
    /**
     * Selects due attempts (processing / unknown, past their sync/timeout
     * window), fetches provider status, applies safe automatic
     * transitions, and raises/updates reconciliation issues on mismatch.
     * Idempotent; safe to run repeatedly and concurrently
     * (withoutOverlapping at the scheduler level regardless).
     *
     * @return int number of attempts examined
     */
    public function reconcileDue(int $limit = 200): int;

    /** On-demand single-attempt reconciliation (the Filament "Reconcile Now" action). */
    public function reconcileAttempt(InstructorPayoutAttempt $attempt): InstructorPayoutAttempt;

    /** Idempotent: an existing open issue of the same type is updated, not duplicated. */
    public function raiseIssue(
        InstructorPayoutAttempt $attempt,
        PayoutReconciliationIssueType $type,
        PayoutReconciliationSeverity $severity,
        string $safeSummary,
    ): InstructorPayoutReconciliationIssue;

    public function assign(InstructorPayoutReconciliationIssue $issue, User $assignee, User $actor): InstructorPayoutReconciliationIssue;

    public function startInvestigating(InstructorPayoutReconciliationIssue $issue, User $actor): InstructorPayoutReconciliationIssue;

    /**
     * Closes the issue row only — never marks a withdrawal paid, never
     * touches an attempt. A mandatory note is required as the evidence
     * record.
     */
    public function resolve(InstructorPayoutReconciliationIssue $issue, User $actor, string $resolutionType, string $note): InstructorPayoutReconciliationIssue;
}
