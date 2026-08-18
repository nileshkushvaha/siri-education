<?php

declare(strict_types=1);

namespace App\Alerts\Enums;

/**
 * Only evidence-backed initial alert sources
 * are represented here: each case corresponds to a real, already-
 * observable failure signal in this codebase (an existing audited
 * event, an existing domain event, or Laravel's own queue-failure
 * event), never a speculative/future source. `category()` is the
 * single source of truth for routing — see
 * `OperationalAlertCategory::notificationPermission()`.
 */
enum OperationalAlertType: string
{
    case MeetingCreationFailed = 'meeting_creation_failed';
    case MeetingCancellationFailed = 'meeting_cancellation_failed';
    case MissingMeetingLink = 'missing_meeting_link';
    case CriticalFailedJob = 'critical_failed_job';
    case PaymentReconciliationIssue = 'payment_reconciliation_issue';
    case PayoutReconciliationIssue = 'payout_reconciliation_issue';
    case WalletRechargeCreditFailed = 'wallet_recharge_credit_failed';
    case RecordingCaptureFailed = 'recording_capture_failed';
    /**
     * A lesson produced more recording artifacts than SIRI's
     * one-recording-per-lesson model stores — not a failure, a product
     * signal. See RecordingIngestionService::reportExtraArtifacts().
     */
    case RecordingMultipleArtifacts = 'recording_multiple_artifacts';

    /**
     * AI estimated spend crossed its configured warning threshold. Like
     * RecordingMultipleArtifacts this is a product signal rather than a
     * failure — nothing is broken, but somebody should decide whether to
     * raise the ceiling or turn a capability off before the budget guard
     * starts blocking runs silently.
     */
    case AiBudgetThresholdReached = 'ai_budget_threshold_reached';

    public function label(): string
    {
        return match ($this) {
            self::MeetingCreationFailed => 'Meeting Creation Failed',
            self::MeetingCancellationFailed => 'Meeting Cancellation Failed',
            self::MissingMeetingLink => 'Missing Meeting Link',
            self::CriticalFailedJob => 'Critical Failed Job',
            self::PaymentReconciliationIssue => 'Payment Reconciliation Issue',
            self::PayoutReconciliationIssue => 'Payout Reconciliation Issue',
            self::WalletRechargeCreditFailed => 'Wallet Recharge Credit Failed',
            self::RecordingCaptureFailed => 'Recording Capture Failed',
            self::RecordingMultipleArtifacts => 'Multiple Recording Artifacts',
        };
    }

    public function category(): OperationalAlertCategory
    {
        return match ($this) {
            self::MeetingCreationFailed,
            self::MeetingCancellationFailed,
            self::MissingMeetingLink,
            self::RecordingCaptureFailed,
            self::RecordingMultipleArtifacts => OperationalAlertCategory::BookingMeeting,

            self::PaymentReconciliationIssue,
            self::PayoutReconciliationIssue,
            self::WalletRechargeCreditFailed,
            // Finance, not System: this is money being spent, and the
            // decision it prompts (raise the ceiling, or switch a
            // capability off) is a spending decision.
            self::AiBudgetThresholdReached => OperationalAlertCategory::Finance,

            self::CriticalFailedJob => OperationalAlertCategory::NotificationQueueSystem,
        };
    }
}
