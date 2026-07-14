<?php

declare(strict_types=1);

namespace App\Quality\Actions;

use App\Models\InstructorQualityAlert;
use App\Quality\Contracts\InstructorQualityAlertRepositoryInterface;
use App\Quality\DTOs\QualitySignalData;
use App\Quality\Enums\InstructorQualityAlertStatus;
use App\Quality\Events\InstructorQualityAlertCreated;
use App\Quality\Events\InstructorQualityAlertEscalated;
use App\Quality\Support\QualityAlertSeverityPolicy;
use App\Quality\Support\QualityAlertThresholdSnapshot;
use App\Services\AuditTrailService;
use App\Settings\ReviewSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of new `quality_alerts` rows — every detector
 * (low-rating, no-show, cancellation, report) builds a
 * QualitySignalData and hands it here. Deduplication is the
 * fingerprint's unique index, not a pre-check: this action always
 * attempts the insert and treats a unique-constraint violation as the
 * idempotent outcome (a concurrent or replayed detector already won),
 * exactly like OpenLessonReviewEligibilityAction. Detection logic
 * itself (thresholds, windows, episode numbers) never lives here —
 * only the "create it exactly once" mechanics do.
 */
final class RecordQualityAlertSignalAction
{
    private const string LOG_NAME = 'quality';

    public function __construct(
        private readonly InstructorQualityAlertRepositoryInterface $alerts,
        private readonly AuditTrailService $audit,
        private readonly ReviewSettings $settings,
    ) {}

    public function execute(QualitySignalData $signal): InstructorQualityAlert
    {
        return DB::transaction(function () use ($signal): InstructorQualityAlert {
            try {
                $alert = $this->alerts->create([
                    'instructor_id' => $signal->instructorId,
                    'alert_type' => $signal->type,
                    'severity' => QualityAlertSeverityPolicy::severityFor($signal->type),
                    'status' => InstructorQualityAlertStatus::Open,
                    'source_type' => $signal->sourceType,
                    'source_id' => $signal->sourceId,
                    'detection_fingerprint' => $signal->fingerprint,
                    'triggered_at' => $signal->triggeredAt,
                    'signal_window_start' => $signal->windowStart,
                    'signal_window_end' => $signal->windowEnd,
                    'signal_count' => $signal->signalCount,
                    'threshold_snapshot' => QualityAlertThresholdSnapshot::capture($this->settings),
                    'summary_metadata' => $signal->summaryMetadata,
                    'version' => 1,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent/replayed detector already won this exact
                // signal — that IS the idempotent outcome, not a failure.
                // The row is guaranteed to exist: the violation itself
                // proves it.
                return $this->alerts->findByFingerprint($signal->fingerprint);
            }

            $this->audit->logSystem(
                self::LOG_NAME,
                'instructor_quality_alert_created',
                sprintf('Quality alert "%s" recorded for instructor %d.', $signal->type->value, $signal->instructorId),
                $alert,
                [
                    'instructor_id' => $signal->instructorId,
                    'alert_type' => $signal->type->value,
                    'severity' => $alert->severity->value,
                    'source_type' => $signal->sourceType->value,
                    'source_id' => $signal->sourceId,
                    'signal_count' => $signal->signalCount,
                ],
            );

            InstructorQualityAlertCreated::dispatch($alert);

            if ($signal->episodeNumber !== null && $signal->episodeNumber > 1) {
                InstructorQualityAlertEscalated::dispatch($alert, $signal->episodeNumber - 1);
            }

            return $alert;
        });
    }
}
