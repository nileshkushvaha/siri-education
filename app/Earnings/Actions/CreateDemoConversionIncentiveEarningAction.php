<?php

declare(strict_types=1);

namespace App\Earnings\Actions;

use App\Earnings\Contracts\InstructorEarningRepositoryInterface;
use App\Earnings\Enums\EarningCalculationType;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Models\DemoConversionIncentiveAward;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Persists the earning row for an awarded demo-conversion incentive.
 * Mirrors CreateInstructorEarningFromLessonAction
 * exactly: `lesson_id` stays null (this is not lesson compensation),
 * `source_type`/`source_id` (the award's own id) is the uniqueness
 * boundary a duplicate/replayed call resolves against.
 */
final class CreateDemoConversionIncentiveEarningAction
{
    private const string SOURCE_TYPE = 'demo_conversion_incentive';

    public function __construct(
        private readonly InstructorEarningRepositoryInterface $earnings,
    ) {}

    public function execute(
        DemoConversionIncentiveAward $award,
        Lesson $paidLesson,
        Lesson $demoLesson,
        ?DateTimeInterface $holdUntil,
    ): InstructorEarning {
        return DB::transaction(fn (): InstructorEarning => $this->earnings->create([
            'lesson_id' => null,
            'booking_id' => $paidLesson->booking_id,
            'instructor_id' => $award->instructor_id,
            'student_id' => $award->student_id,
            'subject_id' => $paidLesson->subject_id,
            'academic_level_id' => $paidLesson->academic_level_id,
            'currency_code' => $award->currency_code,
            'earning_amount_minor' => $award->amount_minor,
            'calculation_type' => EarningCalculationType::DemoConversionIncentive,
            'status' => InstructorEarningStatus::PendingHold,
            'hold_until' => $holdUntil,
            'source_type' => self::SOURCE_TYPE,
            'source_id' => $award->id,
            'metadata' => [
                'award_id' => $award->id,
                'demo_lesson_id' => $demoLesson->id,
                'demo_completed_at' => $demoLesson->completed_at?->toIso8601String(),
                'paid_lesson_id' => $paidLesson->id,
                'paid_lesson_completed_at' => $paidLesson->completed_at?->toIso8601String(),
                'rule_snapshot' => $award->rule_snapshot,
            ],
        ]));
    }

    public static function sourceType(): string
    {
        return self::SOURCE_TYPE;
    }
}
