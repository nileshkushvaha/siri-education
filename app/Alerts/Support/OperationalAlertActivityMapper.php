<?php

declare(strict_types=1);

namespace App\Alerts\Support;

use App\Alerts\DTOs\OperationalAlertSignal;
use App\Alerts\Enums\OperationalAlertSeverity;
use App\Alerts\Enums\OperationalAlertType;
use App\Models\Activity;

/**
 * Given an already-audited Activity row, decides whether it warrants
 * an operational alert and returns a fully-built OperationalAlertSignal
 * — or null to leave it alone. Mirrors `NotificationMapper`'s policy
 * pattern, but for alert creation rather than notification delivery,
 * and reuses the SAME `ActivityCreated` event those admin notifications
 * already fire from — no new event, no new audit call, no change to
 * any of the source services that already call AuditTrailService.
 *
 * Reconciliation-issue activities only produce an alert at Critical
 * severity (`requiring intervention`, requirement #4) — Warning/Info
 * issues stay visible in their own reconciliation queue without paging
 * anyone a second time.
 */
final class OperationalAlertActivityMapper
{
    public function map(Activity $activity): ?OperationalAlertSignal
    {
        return match (true) {
            $activity->log_name === 'bookings' && $activity->event === 'meeting_creation_failed' => $this->signal(
                OperationalAlertType::MeetingCreationFailed,
                OperationalAlertSeverity::High,
                'Meeting creation failed',
                $activity,
            ),

            $activity->log_name === 'bookings' && $activity->event === 'meeting_cancellation_failed' => $this->signal(
                OperationalAlertType::MeetingCancellationFailed,
                OperationalAlertSeverity::Warning,
                'Meeting cancellation failed',
                $activity,
            ),

            $activity->log_name === 'payments'
                && $activity->event === 'booking_payment_reconciliation_issue_detected'
                && $this->propertyString($activity, 'severity') === 'critical' => $this->signal(
                    OperationalAlertType::PaymentReconciliationIssue,
                    OperationalAlertSeverity::Critical,
                    'Payment reconciliation issue requires intervention',
                    $activity,
                ),

            $activity->log_name === 'instructor_payout_execution'
                && $activity->event === 'payout_reconciliation_issue_detected'
                && $this->propertyString($activity, 'severity') === 'critical' => $this->signal(
                    OperationalAlertType::PayoutReconciliationIssue,
                    OperationalAlertSeverity::Critical,
                    'Payout reconciliation issue requires intervention',
                    $activity,
                ),

            default => null,
        };
    }

    private function signal(OperationalAlertType $type, OperationalAlertSeverity $severity, string $title, Activity $activity): OperationalAlertSignal
    {
        return new OperationalAlertSignal(
            type: $type,
            category: $type->category(),
            severity: $severity,
            title: $title,
            summary: (string) $activity->description,
            subjectType: $activity->subject_type,
            subjectId: $activity->subject_id !== null ? (string) $activity->subject_id : null,
            metadata: [
                'activity_id' => $activity->id,
                'type' => $this->propertyString($activity, 'type'),
            ],
            occurredAt: $activity->created_at?->toImmutable(),
        );
    }

    private function propertyString(Activity $activity, string $key): ?string
    {
        $value = $activity->properties[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
