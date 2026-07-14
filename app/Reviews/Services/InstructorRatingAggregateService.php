<?php

declare(strict_types=1);

namespace App\Reviews\Services;

use App\Models\InstructorRatingAggregate;
use App\Models\LessonReview;
use App\Reviews\Actions\RebuildInstructorRatingAggregateAction;
use App\Reviews\Actions\ReconcileReviewContributionAction;
use App\Reviews\Contracts\InstructorRatingAggregateRepositoryInterface;
use App\Reviews\Contracts\InstructorRatingAggregateServiceInterface;
use App\Reviews\DTOs\InstructorRatingSummaryData;
use Illuminate\Support\Facades\Log;
use Throwable;

final class InstructorRatingAggregateService implements InstructorRatingAggregateServiceInterface
{
    public function __construct(
        private readonly ReconcileReviewContributionAction $reconcile,
        private readonly RebuildInstructorRatingAggregateAction $rebuild,
        private readonly InstructorRatingAggregateRepositoryInterface $aggregates,
    ) {}

    public function reconcile(LessonReview $review): void
    {
        $this->reconcile->execute($review);
    }

    public function rebuildForInstructor(int $instructorId): InstructorRatingAggregate
    {
        return $this->rebuild->execute($instructorId);
    }

    public function rebuildAll(): int
    {
        $rebuilt = 0;

        foreach ($this->aggregates->instructorIdsWithReviews() as $instructorId) {
            try {
                $this->rebuild->execute($instructorId);
                $rebuilt++;
            } catch (Throwable $e) {
                // One instructor's failure never stops the batch.
                Log::warning('reviews:rebuild-aggregates — instructor skipped after failure', [
                    'instructor_id' => $instructorId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $rebuilt;
    }

    public function summaryFor(int $instructorId): InstructorRatingSummaryData
    {
        $aggregate = $this->aggregates->findForInstructor($instructorId);

        if ($aggregate === null) {
            return new InstructorRatingSummaryData(
                instructorId: $instructorId,
                reviewCount: 0,
                averageRating: null,
                ratingDistribution: [],
                dimensionAverages: [
                    'teaching_quality' => null,
                    'communication' => null,
                    'punctuality' => null,
                    'preparedness' => null,
                    'learning_value' => null,
                ],
                dimensionCounts: [
                    'teaching_quality' => 0,
                    'communication' => 0,
                    'punctuality' => 0,
                    'preparedness' => 0,
                    'learning_value' => 0,
                ],
                paidReviewCount: 0,
                demoReviewCount: 0,
            );
        }

        return new InstructorRatingSummaryData(
            instructorId: $instructorId,
            reviewCount: $aggregate->eligible_review_count,
            averageRating: $aggregate->overallAverage(),
            ratingDistribution: $aggregate->distribution(),
            dimensionAverages: [
                'teaching_quality' => $aggregate->teachingQualityAverage(),
                'communication' => $aggregate->communicationAverage(),
                'punctuality' => $aggregate->punctualityAverage(),
                'preparedness' => $aggregate->preparednessAverage(),
                'learning_value' => $aggregate->learningValueAverage(),
            ],
            dimensionCounts: [
                'teaching_quality' => $aggregate->teaching_quality_rating_count,
                'communication' => $aggregate->communication_rating_count,
                'punctuality' => $aggregate->punctuality_rating_count,
                'preparedness' => $aggregate->preparedness_rating_count,
                'learning_value' => $aggregate->learning_value_rating_count,
            ],
            paidReviewCount: $aggregate->paid_review_count,
            demoReviewCount: $aggregate->demo_review_count,
        );
    }
}
