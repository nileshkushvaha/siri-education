<?php

declare(strict_types=1);

namespace App\Reviews\Services;

use App\Enums\InstructorStatus;
use App\Models\LessonReview;
use App\Models\User;
use App\Reviews\Contracts\InstructorRatingAggregateServiceInterface;
use App\Reviews\Contracts\LessonReviewRepositoryInterface;
use App\Reviews\Contracts\PublicInstructorReviewServiceInterface;
use App\Reviews\DTOs\InstructorRatingSummaryData;
use App\Reviews\DTOs\PublicInstructorReviewData;
use App\Settings\ReviewSettings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as LengthAwarePaginatorImpl;

/**
 * The only public-facing read path onto reviews. Reuses Phase 17K's
 * `InstructorRatingAggregateService` for the summary (never
 * recalculates an average/distribution from review rows) and maps
 * every review row through `PublicInstructorReviewData::fromReview()`
 * before it leaves this service — an Eloquent `LessonReview` never
 * reaches a public view.
 */
final class PublicInstructorReviewService implements PublicInstructorReviewServiceInterface
{
    public function __construct(
        private readonly InstructorRatingAggregateServiceInterface $ratings,
        private readonly LessonReviewRepositoryInterface $reviews,
        private readonly ReviewSettings $settings,
    ) {}

    public function summaryFor(User $instructor): InstructorRatingSummaryData
    {
        return $this->ratings->summaryFor($instructor->id);
    }

    public function paginatedReviewsFor(User $instructor, int $perPage = 10): LengthAwarePaginator
    {
        if (! $this->isPubliclyVisible($instructor)) {
            return new LengthAwarePaginatorImpl(items: [], total: 0, perPage: $perPage, currentPage: 1);
        }

        $identityMode = $this->settings->public_review_identity_mode;

        return $this->reviews
            ->publicPaginatedForInstructor($instructor->id, $perPage)
            ->through(fn (LessonReview $review): PublicInstructorReviewData => PublicInstructorReviewData::fromReview($review, $identityMode));
    }

    /**
     * The same approval/active/visibility gate `InstructorService::
     * publicProfile()` already enforces before this service is ever
     * called — repeated here defensively so a future caller that
     * skips that gate can never surface a review for a
     * non-public instructor.
     */
    private function isPubliclyVisible(User $instructor): bool
    {
        if (! $instructor->hasRole('instructor') || ! $instructor->isActive()) {
            return false;
        }

        $profile = $instructor->profile;

        return $profile !== null
            && $profile->profile_visibility === 'public'
            && in_array($profile->instructor_status, InstructorStatus::bookable(), true);
    }
}
