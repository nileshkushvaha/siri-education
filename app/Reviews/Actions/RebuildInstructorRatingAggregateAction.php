<?php

declare(strict_types=1);

namespace App\Reviews\Actions;

use App\Models\InstructorRatingAggregate;
use App\Models\LessonReview;
use App\Models\ReviewRatingContribution;
use App\Reviews\Contracts\InstructorRatingAggregateRepositoryInterface;
use App\Reviews\Enums\ReviewableLessonType;
use App\Reviews\Support\ReviewContributionEligibility;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\DB;

/**
 * Full repair for one instructor: recomputes sums/counts/distribution
 * from current ground truth (every LessonReview row for this
 * instructor, cursored — never loaded fully into memory) and replaces
 * the aggregate wholesale, correcting any drift. Also repairs the
 * review_rating_contributions ledger to match, so incremental
 * reconciliation stays consistent afterwards. A reconciliation tool,
 * not the primary update path — the event-driven ReconcileReview
 * ContributionAction handles normal operation.
 */
final class RebuildInstructorRatingAggregateAction
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
        private readonly InstructorRatingAggregateRepositoryInterface $aggregates,
        private readonly AuditTrailService $audit,
    ) {}

    public function execute(int $instructorId): InstructorRatingAggregate
    {
        return DB::transaction(function () use ($instructorId): InstructorRatingAggregate {
            $aggregate = $this->aggregates->lockOrCreateForInstructor($instructorId);
            $before = $aggregate->only(['eligible_review_count', 'overall_rating_sum', 'paid_review_count', 'demo_review_count']);

            $totals = [
                'eligible_review_count' => 0,
                'overall_rating_sum' => 0,
                'rating_distribution' => [],
                'paid_review_count' => 0,
                'demo_review_count' => 0,
            ];

            foreach (self::DIMENSIONS as $dimension) {
                $totals["{$dimension}_sum"] = 0;
                $totals["{$dimension}_count"] = 0;
            }

            $lastPublishedAt = null;

            LessonReview::query()
                ->where('instructor_id', $instructorId)
                ->with('eligibility')
                ->lazyById()
                ->each(function (LessonReview $review) use (&$totals, &$lastPublishedAt): void {
                    if (! ReviewContributionEligibility::qualifies($review)) {
                        $this->repairContribution($review, included: false, lessonType: null);

                        return;
                    }

                    $lessonType = $review->eligibility?->lesson_type;

                    $totals['eligible_review_count']++;
                    $totals['overall_rating_sum'] += $review->overall_rating;

                    $bucket = (string) $review->overall_rating;
                    $totals['rating_distribution'][$bucket] = ($totals['rating_distribution'][$bucket] ?? 0) + 1;

                    if ($lessonType === ReviewableLessonType::Paid) {
                        $totals['paid_review_count']++;
                    } elseif ($lessonType === ReviewableLessonType::Demo) {
                        $totals['demo_review_count']++;
                    }

                    foreach (self::DIMENSIONS as $dimension) {
                        $value = $review->{$dimension};

                        if ($value === null) {
                            continue;
                        }

                        $totals["{$dimension}_sum"] += $value;
                        $totals["{$dimension}_count"]++;
                    }

                    $publishedAt = $review->moderated_at ?? $review->submitted_at;

                    if ($publishedAt !== null && ($lastPublishedAt === null || $publishedAt->isAfter($lastPublishedAt))) {
                        $lastPublishedAt = $publishedAt;
                    }

                    $this->repairContribution($review, included: true, lessonType: $lessonType);
                });

            InstructorRatingAggregate::withAuthorizedMutation(function () use ($aggregate, $totals, $lastPublishedAt): void {
                $aggregate->fill([
                    ...$totals,
                    'last_published_review_at' => $lastPublishedAt,
                    'version' => $aggregate->version + 1,
                ])->save();
            });

            $aggregate->forceFill(['rebuilt_at' => now()->toImmutable()->utc()])->save();

            $after = $aggregate->only(['eligible_review_count', 'overall_rating_sum', 'paid_review_count', 'demo_review_count']);

            $this->audit->logSystem(
                self::LOG_NAME,
                'rating_aggregate_rebuilt',
                sprintf('Instructor %d rating aggregate rebuilt from %d eligible review(s).', $instructorId, $totals['eligible_review_count']),
                $aggregate,
                ['instructor_id' => $instructorId, 'before' => $before, 'after' => $after, 'drifted' => $before !== $after],
            );

            return $aggregate;
        });
    }

    /** Keeps the per-review contribution ledger consistent with what the rebuild determined — repairing any drift there too, but never writes when nothing actually changed. */
    private function repairContribution(LessonReview $review, bool $included, ?ReviewableLessonType $lessonType): void
    {
        $contribution = ReviewRatingContribution::query()->firstOrCreate(
            ['review_id' => $review->id],
            ['instructor_id' => $review->instructor_id],
        );

        $alreadyConverged = $contribution->included === $included
            && (! $included || $contribution->overall_rating === $review->overall_rating);

        if ($alreadyConverged) {
            return;
        }

        $contribution->fill([
            'included' => $included,
            'overall_rating' => $included ? $review->overall_rating : $contribution->overall_rating,
            'teaching_quality_rating' => $included ? $review->teaching_quality_rating : $contribution->teaching_quality_rating,
            'communication_rating' => $included ? $review->communication_rating : $contribution->communication_rating,
            'punctuality_rating' => $included ? $review->punctuality_rating : $contribution->punctuality_rating,
            'preparedness_rating' => $included ? $review->preparedness_rating : $contribution->preparedness_rating,
            'learning_value_rating' => $included ? $review->learning_value_rating : $contribution->learning_value_rating,
            'lesson_type' => $included ? $lessonType?->value : $contribution->lesson_type,
            'applied_review_version' => $included ? $review->version : $contribution->applied_review_version,
            'applied_at' => $included ? ($contribution->applied_at ?? now()->toImmutable()->utc()) : $contribution->applied_at,
            'removed_at' => $included ? null : now()->toImmutable()->utc(),
            'version' => $contribution->version + 1,
        ])->save();
    }
}
