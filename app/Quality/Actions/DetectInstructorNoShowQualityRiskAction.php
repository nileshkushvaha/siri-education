<?php

declare(strict_types=1);

namespace App\Quality\Actions;

use App\Lessons\Enums\LessonOutcome;
use App\Models\Lesson;
use App\Quality\Contracts\InstructorQualityAlertRepositoryInterface;
use App\Quality\Contracts\QualitySignalRepositoryInterface;
use App\Quality\DTOs\QualitySignalData;
use App\Quality\Enums\InstructorQualityAlertType;
use App\Quality\Enums\QualityAlertSourceType;
use App\Quality\Support\QualityAlertFingerprint;
use App\Settings\ReviewSettings;
use Carbon\CarbonImmutable;

/**
 * Consumes a finalized lesson outcome. `LessonOutcome::InstructorNoShow`
 * is the only outcome that counts — student no-show, both-absent,
 * technical-issue, and cancelled outcomes are all structurally
 * excluded by this single equality check, never inferred from a
 * "cancelled" status or any other proxy.
 */
final class DetectInstructorNoShowQualityRiskAction
{
    public function __construct(
        private readonly QualitySignalRepositoryInterface $signals,
        private readonly InstructorQualityAlertRepositoryInterface $alerts,
        private readonly RecordQualityAlertSignalAction $record,
        private readonly ReviewSettings $settings,
    ) {}

    public function execute(Lesson $lesson): void
    {
        if (! $this->settings->quality_alerts_enabled) {
            return;
        }

        if ($lesson->outcome !== LessonOutcome::InstructorNoShow) {
            return;
        }

        $now = CarbonImmutable::now();

        $this->record->execute(new QualitySignalData(
            instructorId: $lesson->instructor_id,
            type: InstructorQualityAlertType::InstructorNoShow,
            sourceType: QualityAlertSourceType::Lesson,
            sourceId: $lesson->id,
            fingerprint: QualityAlertFingerprint::forSource(InstructorQualityAlertType::InstructorNoShow, $lesson->instructor_id, $lesson->id),
            triggeredAt: $now,
            windowStart: null,
            windowEnd: null,
            signalCount: 1,
        ));

        $windowStart = $now->subDays($this->settings->repeated_no_show_window_days);
        $count = $this->signals->countInstructorNoShows($lesson->instructor_id, $windowStart);

        if ($count < $this->settings->repeated_no_show_count) {
            return;
        }

        $episode = 1 + $this->alerts->countTerminalForInstructorAndType($lesson->instructor_id, InstructorQualityAlertType::RepeatedInstructorNoShows);

        $this->record->execute(new QualitySignalData(
            instructorId: $lesson->instructor_id,
            type: InstructorQualityAlertType::RepeatedInstructorNoShows,
            sourceType: QualityAlertSourceType::Lesson,
            sourceId: $lesson->id,
            fingerprint: QualityAlertFingerprint::forEpisode(InstructorQualityAlertType::RepeatedInstructorNoShows, $lesson->instructor_id, $episode),
            triggeredAt: $now,
            windowStart: $windowStart,
            windowEnd: $now,
            signalCount: $count,
            summaryMetadata: ['triggering_lesson_id' => $lesson->id],
            episodeNumber: $episode,
        ));
    }
}
