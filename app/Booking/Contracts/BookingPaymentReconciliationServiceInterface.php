<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\Enums\BookingPaymentReconciliationIssueType;
use App\Booking\Enums\BookingPaymentReconciliationSeverity;
use App\Models\BookingPayment;
use App\Models\BookingPaymentReconciliationIssue;
use App\Models\User;

interface BookingPaymentReconciliationServiceInterface
{
    /**
     * Selects due payment attempts (pending/processing/unknown, past
     * their sync/timeout window), fetches provider status via
     * PaymentProviderInterface::fetchStatus(), applies safe automatic
     * transitions through BookingPaymentService::applyProviderStatus(),
     * and raises/updates reconciliation issues on mismatch. Idempotent;
     * safe to run repeatedly and concurrently (withoutOverlapping at
     * the scheduler level regardless).
     *
     * @return int number of payment attempts examined
     */
    public function reconcileDue(int $limit = 200): int;

    /** On-demand single-attempt reconciliation (the Filament "Reconcile Now" / "Retry verification" action). */
    public function reconcileAttempt(BookingPayment $payment): BookingPayment;

    /** Idempotent: an existing open issue of the same type is updated, not duplicated. */
    public function raiseIssue(
        BookingPayment $payment,
        BookingPaymentReconciliationIssueType $type,
        BookingPaymentReconciliationSeverity $severity,
        string $safeSummary,
    ): BookingPaymentReconciliationIssue;

    public function assign(BookingPaymentReconciliationIssue $issue, User $assignee, User $actor): BookingPaymentReconciliationIssue;

    public function startInvestigating(BookingPaymentReconciliationIssue $issue, User $actor): BookingPaymentReconciliationIssue;

    /**
     * Closes the issue row only — never marks a booking paid, never
     * touches a payment. A mandatory note is required as the evidence
     * record.
     */
    public function resolve(BookingPaymentReconciliationIssue $issue, User $actor, string $resolutionType, string $note): BookingPaymentReconciliationIssue;
}
