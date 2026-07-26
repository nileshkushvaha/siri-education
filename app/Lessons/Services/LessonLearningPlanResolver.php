<?php

declare(strict_types=1);

namespace App\Lessons\Services;

use App\Enums\LearningPlanStatus;
use App\Models\StudentLearningPlan;

/**
 * The single authority on whether a newly created lesson may be
 * associated with a learning plan (SRS §6.17.5 / §6.17.10).
 *
 * A candidate must match on student, subject, and primary instructor —
 * all server-resolved from the booking/lesson, never a client-supplied
 * plan id — and be in a writable status (not Completed/Archived,
 * mirroring HomeworkContextValidator's identical plan-writability
 * gate). Academic level is matched only when BOTH sides have one set;
 * an unset level on either side is not grounds for exclusion, since
 * bookings only carry an ambiguous raw grade and often resolve to no
 * level at all.
 *
 * Zero or more-than-one match both resolve to null — an ambiguous
 * candidate set is exactly as unsafe as no candidate, and this project
 * has no hard "one active plan per student+subject" constraint (SRS:
 * "should normally have one active plan per subject" — a
 * recommendation, not an enforced rule), so more than one open plan
 * for the same student/subject/instructor is a real, if rare,
 * possibility this resolver must handle safely rather than guess.
 */
final class LessonLearningPlanResolver
{
    public function resolve(int $studentId, ?string $subjectId, ?string $academicLevelId, int $instructorId): ?StudentLearningPlan
    {
        if ($subjectId === null) {
            return null;
        }

        $candidates = StudentLearningPlan::query()
            ->where('student_user_id', $studentId)
            ->where('subject_id', $subjectId)
            ->where('primary_instructor_user_id', $instructorId)
            ->whereNotIn('status', [LearningPlanStatus::Completed->value, LearningPlanStatus::Archived->value])
            ->when(
                $academicLevelId !== null,
                fn ($query) => $query->where(
                    fn ($q) => $q->whereNull('academic_level_id')->orWhere('academic_level_id', $academicLevelId),
                ),
            )
            ->limit(2)
            ->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }
}
