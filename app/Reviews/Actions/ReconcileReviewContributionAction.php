<?php

declare(strict_types=1);

namespace App\Reviews\Actions;

use App\Models\InstructorRatingAggregate;
use App\Models\LessonReview;
use App\Models\ReviewRatingContribution;
use App\Reviews\Contracts\InstructorRatingAggregateRepositoryInterface;
use App\Reviews\Contracts\LessonReviewRepositoryInterface;
use App\Reviews\Contracts\ReviewRatingContributionRepositoryInterface;
use App\Reviews\Enums\ReviewableLessonType;
use App\Reviews\Support\ReviewContributionEligibility;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of both `review_rating_contributions` and
 * `instructor_rating_aggregates`. Called by every one of the five
 * review-lifecycle listeners with only a review id/instance — it
 * NEVER trusts the event payload's possibly-stale snapshot, always
 * re-locking and re-reading the review's current status fresh from
 * the database. Because the desired state is always recomputed from
 * that fresh read (never "add" or "remove" as a verb baked into the
 * caller), duplicate events, repeated events, and events arriving out
 * of order all converge to the same correct result — the action only
 * ever compares "should this review count right now" against "does it
 * currently count" and applies nothing when they already agree.
 */
final class ReconcileReviewContributionAction
{
    private const string LOG_NAME = 'reviews';

    private const array DIMENSIONS = [
        'teaching_quality_rating',
        'communication_rating',
        'punctuality_rating',
        'preparedness_rating',
        'learning_value_rating',
    ];

    public function __construct(
        private readonly LessonReviewRepositoryInterface $reviews,
        private readonly ReviewRatingContributionRepositoryInterface $contributions,
        private readonly InstructorRatingAggregateRepositoryInterface $aggregates,
        private readonly AuditTrailService $audit,
    ) {}

    public function execute(LessonReview $review): void
    {
        DB::transaction(function () use ($review): void {
            $review = $this->reviews->lock($review);
            $contribution = $this->contributions->lockOrCreateForReview($review);

            $shouldContribute = ReviewContributionEligibility::qualifies($review);

            if ($shouldContribute === $contribution->included) {
                return; // already converged — idempotent no-op, no aggregate touched
            }

            $aggregate = $this->aggregates->lockOrCreateForInstructor($review->instructor_id);

            $shouldContribute
                ? $this->add($aggregate, $contribution, $review)
                : $this->remove($aggregate, $contribution);
        });
    }

    private function add(InstructorRatingAggregate $aggregate, ReviewRatingContribution $contribution, LessonReview $review): void
    {
        $lessonType = $review->eligibility?->lesson_type;
        $dimensions = $this->dimensionValues($review);

        InstructorRatingAggregate::withAuthorizedMutation(function () use ($aggregate, $review, $lessonType, $dimensions): void {
            $distribution = $aggregate->rating_distribution ?? [];
            $bucket = (string) $review->overall_rating;
            $distribution[$bucket] = ($distribution[$bucket] ?? 0) + 1;

            $aggregate->fill([
                'eligible_review_count' => $aggregate->eligible_review_count + 1,
                'overall_rating_sum' => $aggregate->overall_rating_sum + $review->overall_rating,
                'rating_distribution' => $distribution,
                'paid_review_count' => $aggregate->paid_review_count + ($lessonType === ReviewableLessonType::Paid ? 1 : 0),
                'demo_review_count' => $aggregate->demo_review_count + ($lessonType === ReviewableLessonType::Demo ? 1 : 0),
                'last_published_review_at' => $review->moderated_at ?? now()->toImmutable()->utc(),
                'version' => $aggregate->version + 1,
            ]);

            foreach ($dimensions as $dimension => $value) {
                if ($value === null) {
                    continue;
                }

                $aggregate->fill([
                    "{$dimension}_sum" => $aggregate->{"{$dimension}_sum"} + $value,
                    "{$dimension}_count" => $aggregate->{"{$dimension}_count"} + 1,
                ]);
            }

            $aggregate->save();
        });

        ReviewRatingContribution::withAuthorizedMutation(fn () => $contribution->fill([
            'included' => true,
            'overall_rating' => $review->overall_rating,
            'teaching_quality_rating' => $dimensions['teaching_quality_rating'],
            'communication_rating' => $dimensions['communication_rating'],
            'punctuality_rating' => $dimensions['punctuality_rating'],
            'preparedness_rating' => $dimensions['preparedness_rating'],
            'learning_value_rating' => $dimensions['learning_value_rating'],
            'lesson_type' => $lessonType?->value,
            'applied_review_version' => $review->version,
            'applied_at' => now()->toImmutable()->utc(),
            'removed_at' => null,
            'version' => $contribution->version + 1,
        ])->save());

        $this->audit->logSystem(
            self::LOG_NAME,
            'rating_contribution_added',
            sprintf('Review %s now contributes to instructor %d\'s rating aggregate.', $review->id, $review->instructor_id),
            $contribution,
            ['review_id' => $review->id, 'instructor_id' => $review->instructor_id, 'overall_rating' => $review->overall_rating],
        );
    }

    private function remove(InstructorRatingAggregate $aggregate, ReviewRatingContribution $contribution): void
    {
        $lessonType = $contribution->lesson_type;

        InstructorRatingAggregate::withAuthorizedMutation(function () use ($aggregate, $contribution, $lessonType): void {
            $distribution = $aggregate->rating_distribution ?? [];
            $bucket = (string) $contribution->overall_rating;

            if (isset($distribution[$bucket])) {
                $distribution[$bucket] = $this->clampedSubtract($distribution[$bucket], 1, $aggregate, 'rating_distribution.'.$bucket);

                if ($distribution[$bucket] === 0) {
                    unset($distribution[$bucket]);
                }
            }

            $aggregate->fill([
                'eligible_review_count' => $this->clampedSubtract($aggregate->eligible_review_count, 1, $aggregate, 'eligible_review_count'),
                'overall_rating_sum' => $this->clampedSubtract($aggregate->overall_rating_sum, (int) $contribution->overall_rating, $aggregate, 'overall_rating_sum'),
                'rating_distribution' => $distribution,
                'paid_review_count' => $lessonType === ReviewableLessonType::Paid->value
                    ? $this->clampedSubtract($aggregate->paid_review_count, 1, $aggregate, 'paid_review_count')
                    : $aggregate->paid_review_count,
                'demo_review_count' => $lessonType === ReviewableLessonType::Demo->value
                    ? $this->clampedSubtract($aggregate->demo_review_count, 1, $aggregate, 'demo_review_count')
                    : $aggregate->demo_review_count,
                'version' => $aggregate->version + 1,
            ]);

            foreach (self::DIMENSIONS as $dimension) {
                $value = $contribution->{$dimension};

                if ($value === null) {
                    continue;
                }

                $aggregate->fill([
                    "{$dimension}_sum" => $this->clampedSubtract($aggregate->{"{$dimension}_sum"}, $value, $aggregate, "{$dimension}_sum"),
                    "{$dimension}_count" => $this->clampedSubtract($aggregate->{"{$dimension}_count"}, 1, $aggregate, "{$dimension}_count"),
                ]);
            }

            $aggregate->save();
        });

        ReviewRatingContribution::withAuthorizedMutation(fn () => $contribution->fill([
            'included' => false,
            'removed_at' => now()->toImmutable()->utc(),
            'version' => $contribution->version + 1,
        ])->save());

        $this->audit->logSystem(
            self::LOG_NAME,
            'rating_contribution_removed',
            sprintf('Review %s no longer contributes to instructor %d\'s rating aggregate.', $contribution->review_id, $contribution->instructor_id),
            $contribution,
            ['review_id' => $contribution->review_id, 'instructor_id' => $contribution->instructor_id],
        );
    }

    /** @return array<string, ?int> */
    private function dimensionValues(LessonReview $review): array
    {
        return [
            'teaching_quality_rating' => $review->teaching_quality_rating,
            'communication_rating' => $review->communication_rating,
            'punctuality_rating' => $review->punctuality_rating,
            'preparedness_rating' => $review->preparedness_rating,
            'learning_value_rating' => $review->learning_value_rating,
        ];
    }

    /** Never lets a sum/count go negative; logs safely (no raw content, just field/instructor ids) when drift is caught. */
    private function clampedSubtract(int $current, int $delta, InstructorRatingAggregate $aggregate, string $field): int
    {
        $result = $current - $delta;

        if ($result >= 0) {
            return $result;
        }

        $this->audit->logSystem(
            self::LOG_NAME,
            'rating_aggregate_drift_detected',
            sprintf('Instructor %d rating aggregate field "%s" would go negative — clamped to 0. Run reviews:rebuild-aggregates.', $aggregate->instructor_id, $field),
            $aggregate,
            ['instructor_id' => $aggregate->instructor_id, 'field' => $field, 'attempted_value' => $result],
        );

        return 0;
    }
}
