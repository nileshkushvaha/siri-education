<?php

declare(strict_types=1);

namespace App\Quality\Actions;

use App\Models\ReviewReport;
use App\Quality\DTOs\QualitySignalData;
use App\Quality\Enums\InstructorQualityAlertType;
use App\Quality\Enums\QualityAlertSourceType;
use App\Quality\Support\QualityAlertFingerprint;
use App\Reviews\Enums\ReviewReportReason;
use App\Settings\ReviewSettings;
use Carbon\CarbonImmutable;

/**
 * Consumes an upheld review report. Only a fixed set of "serious"
 * reasons ever create a quality signal — Spam/IrrelevantContent/
 * PrivacyConcern/Other never do, regardless of upheld status.
 * Dismissed and Duplicate reports never reach this action at all
 * (only ReviewReportUpheld triggers it). The fingerprint keys on the
 * underlying *review*, not the report — so several separate reports
 * against the same review, upheld individually or together, still
 * collapse to exactly one alert.
 */
final class DetectSeriousReviewReportQualityRiskAction
{
    private const array SERIOUS_REASONS = [
        ReviewReportReason::PersonalInformation,
        ReviewReportReason::OffPlatformSolicitation,
        ReviewReportReason::HateOrHarassment,
        ReviewReportReason::AbusiveLanguage,
        ReviewReportReason::FakeOrMisleading,
    ];

    public function __construct(
        private readonly RecordQualityAlertSignalAction $record,
        private readonly ReviewSettings $settings,
    ) {}

    public function execute(ReviewReport $report): void
    {
        if (! $this->settings->quality_alerts_enabled) {
            return;
        }

        if (! in_array($report->reason, self::SERIOUS_REASONS, strict: true)) {
            return;
        }

        $review = $report->review;

        $this->record->execute(new QualitySignalData(
            instructorId: $review->instructor_id,
            type: InstructorQualityAlertType::SeriousReviewReport,
            sourceType: QualityAlertSourceType::LessonReview,
            sourceId: $review->id,
            fingerprint: QualityAlertFingerprint::forSource(InstructorQualityAlertType::SeriousReviewReport, $review->instructor_id, $review->id),
            triggeredAt: CarbonImmutable::now(),
            windowStart: null,
            windowEnd: null,
            signalCount: 1,
            summaryMetadata: ['reason' => $report->reason->value],
        ));
    }
}
