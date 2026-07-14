<?php

declare(strict_types=1);

namespace App\Reviews\Actions;

use App\Models\LessonReview;
use App\Models\ReviewTag;
use App\Models\User;
use App\Reviews\Contracts\InstructorRatingAggregateServiceInterface;
use App\Reviews\Contracts\LessonReviewRepositoryInterface;
use App\Reviews\Contracts\LessonReviewRevisionRepositoryInterface;
use App\Reviews\DTOs\EditStudentReviewData;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Enums\StudentReviewStatus;
use App\Reviews\Events\StudentReviewEdited;
use App\Reviews\Events\StudentReviewModerationRequired;
use App\Reviews\Exceptions\ReviewEligibilityException;
use App\Reviews\Exceptions\ReviewValidationException;
use App\Reviews\Support\ReviewContentSanitizer;
use App\Reviews\Support\ReviewEditPolicy;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of student review edits (Phase 17R). One
 * transaction: lock → revalidate editability (ReviewEditPolicy, under
 * lock — never trusted from the page that rendered the form) →
 * validate against the review's OWN stored settings snapshot (a
 * historical review keeps its original rating policy forever) →
 * sanitize → append the immutable pre-edit revision → apply the edit
 * with exactly one version increment → synchronously reconcile the
 * rating contribution while the review is no longer Published (so the
 * OLD contribution is removed before any re-publication can make the
 * reconciler see "already included" and skip the value change) →
 * audit → dispatch after commit.
 *
 * Re-moderation statuses: a clean public edit returns to Submitted
 * (the existing automatic pipeline re-runs via the StudentReviewEdited
 * listener and may auto-publish per current policy); a flagged public
 * edit goes to Flagged for a human; clean private feedback stays
 * Private; flagged private feedback goes to Flagged with its private
 * mode intact, so an approval can only ever return it to Private.
 * An edited Published review leaves public visibility immediately —
 * old content is never shown while the edit awaits moderation.
 */
final class EditStudentReviewAction
{
    private const string LOG_NAME = 'reviews';

    public function __construct(
        private readonly LessonReviewRepositoryInterface $reviews,
        private readonly LessonReviewRevisionRepositoryInterface $revisions,
        private readonly TransitionReviewStatusAction $transition,
        private readonly InstructorRatingAggregateServiceInterface $aggregates,
        private readonly ReviewEditPolicy $editPolicy,
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * @throws ReviewEligibilityException
     * @throws ReviewValidationException
     */
    public function execute(LessonReview $review, User $student, EditStudentReviewData $data): LessonReview
    {
        return DB::transaction(function () use ($review, $student, $data): LessonReview {
            $review = $this->reviews->lock($review);

            $editability = $this->editPolicy->evaluate($review, $student);

            if (! $editability->editable) {
                throw new ReviewEligibilityException($editability->reason ?? 'This review cannot be edited.');
            }

            $snapshot = $review->settings_snapshot;
            $sanitized = ReviewContentSanitizer::sanitize($data->content);
            $this->assertContentLength($sanitized->content, $snapshot);
            $this->assertRatings($data, $snapshot);
            $tags = $this->resolveTags($data->tagKeys, $review->review_mode, $snapshot);

            $previousStatus = $review->status;
            $previousVersion = $review->version;

            $this->revisions->append([
                'lesson_review_id' => $review->id,
                'review_version' => $previousVersion,
                'previous_overall_rating' => $review->overall_rating,
                'previous_teaching_quality_rating' => $review->teaching_quality_rating,
                'previous_communication_rating' => $review->communication_rating,
                'previous_punctuality_rating' => $review->punctuality_rating,
                'previous_preparedness_rating' => $review->preparedness_rating,
                'previous_learning_value_rating' => $review->learning_value_rating,
                'previous_content' => $review->content,
                'previous_tags' => $review->tags,
                'previous_status' => $previousStatus,
                'previous_sanitization_metadata' => $review->sanitization_metadata,
                'previous_moderation_snapshot' => $review->moderation_snapshot,
                'edited_by' => $student->id,
                'edited_at' => now()->toImmutable()->utc(),
            ]);

            $next = $this->statusAfterEdit($review, $sanitized->isClean());

            $fields = [
                'overall_rating' => $data->overallRating,
                'teaching_quality_rating' => $data->teachingQualityRating,
                'communication_rating' => $data->communicationRating,
                'punctuality_rating' => $data->punctualityRating,
                'preparedness_rating' => $data->preparednessRating,
                'learning_value_rating' => $data->learningValueRating,
                'content' => $sanitized->content,
                'tags' => $tags,
                'sanitization_metadata' => [
                    'flags' => array_map(fn ($f) => $f->value, $sanitized->flags),
                    'had_unsafe_content' => ! $sanitized->isClean(),
                ],
            ];

            if ($next !== $review->status) {
                $review = $this->transition->execute($review, $next, $fields);
            } else {
                $review->fill([...$fields, 'version' => $review->version + 1])->save();
            }

            // Remove the old rating contribution NOW, while the review
            // is provably not Published — if this waited for the
            // after-commit listener, an auto-republished edit could be
            // seen by the reconciler as "already included" and keep the
            // stale pre-edit values in the aggregate forever.
            $this->aggregates->reconcile($review);

            $this->audit->logUser(
                $student,
                self::LOG_NAME,
                'student_review_edited',
                sprintf('Review %s edited by its student (v%d → v%d).', $review->id, $previousVersion, $review->version),
                $review,
                [
                    'review_id' => $review->id,
                    'previous_status' => $previousStatus->value,
                    'new_status' => $review->status->value,
                    'previous_version' => $previousVersion,
                    'new_version' => $review->version,
                    'changed_fields' => $this->changedFieldNames($review),
                    'content_flags' => array_map(fn ($f) => $f->value, $sanitized->flags),
                ],
            );

            StudentReviewEdited::dispatch($review);

            // Same reasoning as SubmitLessonReviewAction: Flagged is final
            // the instant it's set, so dispatch here rather than deriving
            // it from StudentReviewEdited in a listener.
            if ($review->status === StudentReviewStatus::Flagged) {
                StudentReviewModerationRequired::dispatch($review);
            }

            return $review;
        });
    }

    /**
     * The re-moderation target. Clean public edits re-enter the
     * automatic pipeline; anything flagged waits for a human; private
     * feedback never leaves the private track.
     */
    private function statusAfterEdit(LessonReview $review, bool $isClean): StudentReviewStatus
    {
        if ($review->review_mode === LessonReviewEligibilityMode::PrivateFeedback) {
            return $isClean ? StudentReviewStatus::Private : StudentReviewStatus::Flagged;
        }

        return $isClean ? StudentReviewStatus::Submitted : StudentReviewStatus::Flagged;
    }

    /** @return list<string> attribute names only — never values */
    private function changedFieldNames(LessonReview $review): array
    {
        return array_values(array_diff(array_keys($review->getChanges()), ['updated_at', 'version', 'status']));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     *
     * @throws ReviewValidationException
     */
    private function assertRatings(EditStudentReviewData $data, array $snapshot): void
    {
        $min = (int) ($snapshot['rating_min'] ?? 1);
        $max = (int) ($snapshot['rating_max'] ?? 5);

        $this->assertRatingInRange('overall rating', $data->overallRating, $min, $max);

        $dimensions = [
            'teaching quality rating' => $data->teachingQualityRating,
            'communication rating' => $data->communicationRating,
            'punctuality rating' => $data->punctualityRating,
            'preparedness rating' => $data->preparednessRating,
            'learning value rating' => $data->learningValueRating,
        ];

        foreach ($dimensions as $label => $value) {
            if ($value === null) {
                continue;
            }

            if (! ($snapshot['rating_dimensions_enabled'] ?? false)) {
                throw new ReviewValidationException('Dimension ratings are not accepted for this review.');
            }

            $this->assertRatingInRange($label, $value, $min, $max);
        }
    }

    /** @throws ReviewValidationException */
    private function assertRatingInRange(string $label, int $value, int $min, int $max): void
    {
        if ($value < $min || $value > $max) {
            throw new ReviewValidationException(sprintf('The %s must be between %d and %d.', $label, $min, $max));
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     *
     * @throws ReviewValidationException
     */
    private function assertContentLength(?string $content, array $snapshot): void
    {
        if ($content === null || trim($content) === '') {
            if ($snapshot['written_review_required'] ?? false) {
                throw new ReviewValidationException('Written feedback is required.');
            }

            return;
        }

        $length = mb_strlen($content);
        $minLength = (int) ($snapshot['review_min_length'] ?? 0);
        $maxLength = (int) ($snapshot['review_max_length'] ?? PHP_INT_MAX);

        if ($length < $minLength) {
            throw new ReviewValidationException(sprintf('Written feedback must be at least %d characters.', $minLength));
        }

        if ($length > $maxLength) {
            throw new ReviewValidationException(sprintf('Written feedback must be at most %d characters.', $maxLength));
        }
    }

    /**
     * @param  list<string>  $tagKeys
     * @param  array<string, mixed>  $snapshot
     * @return list<array{key: string, label: string}>
     *
     * @throws ReviewValidationException
     */
    private function resolveTags(array $tagKeys, LessonReviewEligibilityMode $mode, array $snapshot): array
    {
        $unique = array_values(array_unique($tagKeys));

        if ($unique === []) {
            return [];
        }

        $maxTags = (int) ($snapshot['review_max_tags'] ?? 5);

        if (count($unique) > $maxTags) {
            throw new ReviewValidationException(sprintf('A review may select at most %d tags.', $maxTags));
        }

        $tags = ReviewTag::query()
            ->active()
            ->applicableTo($mode)
            ->whereIn('key', $unique)
            ->get();

        if ($tags->count() !== count($unique)) {
            throw new ReviewValidationException('One or more selected tags are not available.');
        }

        return $tags
            ->map(fn (ReviewTag $tag): array => ['key' => $tag->key, 'label' => $tag->label])
            ->values()
            ->all();
    }
}
