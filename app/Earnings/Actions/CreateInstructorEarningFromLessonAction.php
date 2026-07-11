<?php

declare(strict_types=1);

namespace App\Earnings\Actions;

use App\Earnings\Contracts\InstructorEarningRepositoryInterface;
use App\Earnings\DTOs\EarningCalculation;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Models\Currency;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use Illuminate\Support\Facades\DB;

/**
 * Persists the earning row for an eligible completed lesson.
 * Eligibility, idempotency, and the amount calculation are
 * InstructorEarningService's job — this action only writes.
 */
final class CreateInstructorEarningFromLessonAction
{
    public function __construct(
        private readonly InstructorEarningRepositoryInterface $earnings,
    ) {}

    public function execute(Lesson $lesson, EarningCalculation $calculation, ?\DateTimeInterface $holdUntil): InstructorEarning
    {
        return DB::transaction(fn (): InstructorEarning => $this->earnings->create([
            'lesson_id' => $lesson->id,
            'booking_id' => $lesson->booking_id,
            'instructor_id' => $lesson->instructor_id,
            'student_id' => $lesson->student_id,
            'subject_id' => $lesson->subject_id,
            'subject_topic_id' => $lesson->subject_topic_id,
            'academic_level_id' => $lesson->academic_level_id,
            'currency_id' => Currency::query()->where('code', $calculation->currencyCode)->value('id'),
            'currency_code' => $calculation->currencyCode,
            'student_amount_minor' => $calculation->studentAmountMinor,
            'earning_amount_minor' => $calculation->earningAmountMinor,
            'platform_margin_minor' => $calculation->platformMarginMinor,
            'calculation_type' => $calculation->type,
            'calculation_value' => $calculation->value,
            'status' => InstructorEarningStatus::PendingHold,
            'hold_until' => $holdUntil,
            'source_type' => 'lesson',
            'source_id' => $lesson->id,
            'metadata' => [
                'lesson_completed_at' => $lesson->completed_at?->toIso8601String(),
                'booking_reference' => $lesson->metadata['booking_reference'] ?? null,
            ],
        ]));
    }
}
