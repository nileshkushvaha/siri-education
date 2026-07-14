<?php

declare(strict_types=1);

namespace App\Quality\Repositories;

use App\Booking\Enums\BookingActor;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Booking;
use App\Models\Lesson;
use App\Models\LessonReview;
use App\Quality\Contracts\QualitySignalRepositoryInterface;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Enums\StudentReviewStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

final class QualitySignalRepository implements QualitySignalRepositoryInterface
{
    public function countLowPublishedReviews(int $instructorId, int $threshold, CarbonImmutable $since): int
    {
        return $this->lowPublishedReviewsQuery($instructorId, $threshold, $since)->count();
    }

    public function countInstructorNoShows(int $instructorId, CarbonImmutable $since): int
    {
        return $this->instructorNoShowLessonsQuery($since)
            ->where('instructor_id', $instructorId)
            ->count();
    }

    public function countInstructorAttributedCancellations(int $instructorId, CarbonImmutable $since): int
    {
        return Booking::query()
            ->where('host_id', $instructorId)
            ->where('cancelled_by', BookingActor::Host)
            ->where('cancelled_at', '>=', $since)
            ->count();
    }

    public function recentLowPublishedReviews(CarbonImmutable $since, int $threshold): LazyCollection
    {
        return $this->lowPublishedReviewsQuery(null, $threshold, $since)->lazyById();
    }

    public function recentInstructorNoShowLessons(CarbonImmutable $since): LazyCollection
    {
        return $this->instructorNoShowLessonsQuery($since)->lazyById();
    }

    private function lowPublishedReviewsQuery(?int $instructorId, int $threshold, CarbonImmutable $since): Builder
    {
        return LessonReview::query()
            ->when($instructorId !== null, fn (Builder $query) => $query->where('instructor_id', $instructorId))
            ->where('status', StudentReviewStatus::Published)
            ->where('review_mode', LessonReviewEligibilityMode::PublicReview)
            ->where('overall_rating', '<=', $threshold)
            ->where('submitted_at', '>=', $since);
    }

    private function instructorNoShowLessonsQuery(CarbonImmutable $since): Builder
    {
        return Lesson::query()
            ->where('outcome', LessonOutcome::InstructorNoShow)
            ->where('outcome_finalized_at', '>=', $since);
    }
}
