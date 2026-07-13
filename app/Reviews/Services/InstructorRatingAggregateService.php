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
            paidReviewCount: $aggregate->paid_review_count,
            demoReviewCount: $aggregate->demo_review_count,
        );
    }
}
