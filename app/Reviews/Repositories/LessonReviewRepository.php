<?php

declare(strict_types=1);

namespace App\Reviews\Repositories;

use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Reviews\Contracts\LessonReviewRepositoryInterface;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Enums\StudentReviewStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class LessonReviewRepository implements LessonReviewRepositoryInterface
{
    public function findForEligibility(LessonReviewEligibility $eligibility): ?LessonReview
    {
        return LessonReview::query()->where('eligibility_id', $eligibility->id)->first();
    }

    public function create(array $attributes): LessonReview
    {
        return LessonReview::query()->create($attributes);
    }

    public function lock(LessonReview $review): LessonReview
    {
        return LessonReview::query()
            ->whereKey($review->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function publicPaginatedForInstructor(int $instructorId, int $perPage): LengthAwarePaginator
    {
        return LessonReview::query()
            ->where('instructor_id', $instructorId)
            ->where('status', StudentReviewStatus::Published)
            ->where('review_mode', LessonReviewEligibilityMode::PublicReview)
            // Explicit column list — moderation_reason, moderation_snapshot,
            // moderated_by, settings_snapshot, sanitization_metadata, and
            // booking_id never even reach PHP memory for a public request.
            ->select([
                'id', 'eligibility_id', 'lesson_id', 'student_id', 'instructor_id',
                'overall_rating', 'teaching_quality_rating', 'communication_rating',
                'punctuality_rating', 'preparedness_rating', 'learning_value_rating',
                'content', 'tags', 'submitted_at', 'moderated_at',
            ])
            ->with([
                'student:id,first_name,status',
                'student.profile:id,user_id,student_status',
                'eligibility:id,lesson_id,lesson_type,status',
                'lesson:id,outcome',
            ])
            ->orderByDesc('moderated_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
