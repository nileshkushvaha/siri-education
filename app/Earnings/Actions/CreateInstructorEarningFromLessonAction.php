<?php

declare(strict_types=1);

namespace App\Earnings\Actions;

use App\Earnings\Contracts\InstructorEarningRepositoryInterface;
use App\Earnings\DTOs\CompensationResolution;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use Illuminate\Support\Facades\DB;

/**
 * Persists the earning row for an eligible completed lesson from a
 * resolved compensation agreement. Eligibility, idempotency, and the
 * amount resolution are upstream jobs — this action only writes.
 * Phase 14.2: no student amount or platform margin is ever persisted
 * for new earnings; the immutable agreement snapshot lives in metadata.
 */
final class CreateInstructorEarningFromLessonAction
{
    public function __construct(
        private readonly InstructorEarningRepositoryInterface $earnings,
    ) {}

    public function execute(Lesson $lesson, CompensationResolution $resolution, ?\DateTimeInterface $holdUntil): InstructorEarning
    {
        return DB::transaction(fn (): InstructorEarning => $this->earnings->create([
            'lesson_id' => $lesson->id,
            'booking_id' => $lesson->booking_id,
            'instructor_id' => $lesson->instructor_id,
            'student_id' => $lesson->student_id,
            'subject_id' => $lesson->subject_id,
            'subject_topic_id' => $lesson->subject_topic_id,
            'academic_level_id' => $lesson->academic_level_id,
            'currency_id' => $resolution->currencyId,
            'currency_code' => $resolution->currencyCode,
            'earning_amount_minor' => $resolution->amountMinor,
            'calculation_type' => $resolution->calculationType,
            'status' => InstructorEarningStatus::PendingHold,
            'hold_until' => $holdUntil,
            'source_type' => 'lesson',
            'source_id' => $lesson->id,
            'metadata' => [
                ...$resolution->snapshot(),
                'lesson_id' => $lesson->id,
                'lesson_scheduled_start_at' => $lesson->starts_at?->toIso8601String(),
                'lesson_completed_at' => $lesson->completed_at?->toIso8601String(),
                'booking_reference' => $lesson->metadata['booking_reference'] ?? null,
            ],
        ]));
    }
}
