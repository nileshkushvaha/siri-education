<?php

declare(strict_types=1);

namespace App\Reviews\Actions;

use App\Models\LessonReviewEligibility;
use App\Models\ReviewTag;
use App\Models\User;
use App\Reviews\Contracts\LessonReviewEligibilityRepositoryInterface;
use App\Reviews\Contracts\LessonReviewRepositoryInterface;
use App\Reviews\DTOs\SubmitReviewResult;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Enums\LessonReviewEligibilityStatus;
use App\Reviews\Enums\StudentReviewStatus;
use App\Reviews\Events\StudentReviewModerationRequired;
use App\Reviews\Events\StudentReviewSubmitted;
use App\Reviews\Exceptions\ReviewEligibilityException;
use App\Reviews\Exceptions\ReviewValidationException;
use App\Reviews\Support\ReviewContentSanitizer;
use App\Services\AuditTrailService;
use App\Settings\ReviewSettings;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of student reviews (Phase 17I). Authorization is
 * the caller's job (StudentReviewService checks the eligibility policy
 * before calling this); this action assumes the acting student is
 * already authorized and focuses purely on: lock → revalidate →
 * validate input → sanitize → create → mark eligibility Used, all in
 * one transaction. review_mode is always taken from the eligibility
 * itself — a submission payload can never choose or change it.
 */
final class SubmitLessonReviewAction
{
    private const string LOG_NAME = 'reviews';

    public function __construct(
        private readonly LessonReviewEligibilityRepositoryInterface $eligibilities,
        private readonly LessonReviewRepositoryInterface $reviews,
        private readonly AuditTrailService $audit,
        private readonly ReviewSettings $settings,
    ) {}

    /**
     * @throws ReviewEligibilityException
     * @throws ReviewValidationException
     */
    public function execute(LessonReviewEligibility $eligibility, User $student, SubmitStudentReviewData $data): SubmitReviewResult
    {
        return DB::transaction(function () use ($eligibility, $student, $data): SubmitReviewResult {
            $eligibility = $this->eligibilities->lock($eligibility);

            // Duplicate/concurrent submission against an already-used
            // window: idempotent — return the existing review, write
            // nothing new. Every other non-open state is a hard reject.
            if ($eligibility->status !== LessonReviewEligibilityStatus::Open) {
                if ($eligibility->status === LessonReviewEligibilityStatus::Used) {
                    $existing = $this->reviews->findForEligibility($eligibility);

                    if ($existing !== null) {
                        return new SubmitReviewResult($existing, applied: false);
                    }
                }

                throw new ReviewEligibilityException(sprintf(
                    'This review eligibility is "%s" — a submission is not accepted.',
                    $eligibility->status->value,
                ));
            }

            // Master switch (Phase 17U.2 §3): even an eligibility window
            // opened before reviews were disabled must stop accepting a
            // genuinely new submission the instant the switch flips off.
            // The idempotent "already used" branch above runs first, so
            // this never blocks a harmless retry of a submission that
            // already happened while reviews were enabled.
            if (! $this->settings->reviews_enabled) {
                throw new ReviewEligibilityException('Reviews are currently disabled.');
            }

            $this->assertStillValid($eligibility, $student);

            $sanitized = ReviewContentSanitizer::sanitize($data->content);
            $this->assertContentLength($sanitized->content);
            $this->assertRatings($data);
            $tags = $this->resolveTags($data->tagKeys, $eligibility->eligibility_mode);

            $status = match (true) {
                ! $sanitized->isClean() => StudentReviewStatus::Flagged,
                $eligibility->eligibility_mode === LessonReviewEligibilityMode::PrivateFeedback => StudentReviewStatus::Private,
                default => StudentReviewStatus::Submitted,
            };

            $review = $this->reviews->create([
                'eligibility_id' => $eligibility->id,
                'lesson_id' => $eligibility->lesson_id,
                'booking_id' => $eligibility->booking_id,
                'student_id' => $eligibility->student_id,
                'instructor_id' => $eligibility->instructor_id,
                'review_mode' => $eligibility->eligibility_mode,
                'overall_rating' => $data->overallRating,
                'teaching_quality_rating' => $data->teachingQualityRating,
                'communication_rating' => $data->communicationRating,
                'punctuality_rating' => $data->punctualityRating,
                'preparedness_rating' => $data->preparednessRating,
                'learning_value_rating' => $data->learningValueRating,
                'content' => $sanitized->content,
                'tags' => $tags,
                'status' => $status,
                'submitted_at' => now()->toImmutable()->utc(),
                'settings_snapshot' => $this->ratingSettingsSnapshot(),
                'sanitization_metadata' => [
                    'flags' => array_map(fn ($f) => $f->value, $sanitized->flags),
                    'had_unsafe_content' => ! $sanitized->isClean(),
                ],
                'version' => 1,
            ]);

            $eligibility->history = [...($eligibility->history ?? []), $eligibility->snapshotForHistory()];
            $eligibility->fill([
                'status' => LessonReviewEligibilityStatus::Used,
                'used_at' => $review->submitted_at,
                'version' => $eligibility->version + 1,
            ])->save();

            // Never the raw content — only ids, mode, status, and flag
            // categories reach the audit trail.
            $this->audit->logUser(
                $student,
                self::LOG_NAME,
                'student_review_submitted',
                sprintf('Lesson %s: student submitted a %s (%s).', $eligibility->lesson_id, $eligibility->eligibility_mode->label(), $status->label()),
                $review,
                [
                    'lesson_id' => $eligibility->lesson_id,
                    'booking_id' => $eligibility->booking_id,
                    'eligibility_id' => $eligibility->id,
                    'review_mode' => $eligibility->eligibility_mode->value,
                    'status' => $status->value,
                    'content_flags' => array_map(fn ($f) => $f->value, $sanitized->flags),
                    'overall_rating' => $data->overallRating,
                ],
            );

            StudentReviewSubmitted::dispatch($review);

            // Flagged is a final moderation-required state the instant it's
            // set — nothing later auto-clears it, so dispatching here
            // (rather than deriving it from StudentReviewSubmitted in a
            // listener) can never race the automatic-moderation listener.
            if ($status === StudentReviewStatus::Flagged) {
                StudentReviewModerationRequired::dispatch($review);
            }

            return new SubmitReviewResult($review, applied: true);
        });
    }

    /** @throws ReviewEligibilityException */
    private function assertStillValid(LessonReviewEligibility $eligibility, User $student): void
    {
        if ($eligibility->student_id !== $student->id) {
            throw new ReviewEligibilityException('You may only submit a review for your own eligibility.');
        }

        if ($eligibility->eligibility_mode === LessonReviewEligibilityMode::Disabled) {
            throw new ReviewEligibilityException('This eligibility mode does not accept submissions.');
        }

        $now = now();

        if ($now->isAfter($eligibility->expires_at)) {
            throw new ReviewEligibilityException('This review eligibility has expired.');
        }

        if ($now->isBefore($eligibility->opens_at)) {
            throw new ReviewEligibilityException('This review eligibility is not open yet.');
        }

        $lesson = $eligibility->lesson;
        $booking = $eligibility->booking;

        // Structural guard: the lesson/booking must still reference the
        // exact same participants the eligibility was opened for.
        if ($lesson === null || $booking === null
            || $lesson->student_id !== $eligibility->student_id
            || $lesson->instructor_id !== $eligibility->instructor_id
            || $booking->student_id !== $eligibility->student_id
            || $booking->instructor_id !== $eligibility->instructor_id) {
            throw new ReviewEligibilityException('The lesson or booking no longer matches this eligibility.');
        }
    }

    /** @throws ReviewValidationException */
    private function assertRatings(SubmitStudentReviewData $data): void
    {
        $min = $this->settings->rating_min;
        $max = $this->settings->rating_max;

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

            if (! $this->settings->rating_dimensions_enabled) {
                throw new ReviewValidationException('Dimension ratings are not accepted right now.');
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

    /** @throws ReviewValidationException */
    private function assertContentLength(?string $content): void
    {
        if ($content === null || trim($content) === '') {
            if ($this->settings->written_review_required) {
                throw new ReviewValidationException('Written feedback is required.');
            }

            return;
        }

        $length = mb_strlen($content);

        if ($length < $this->settings->review_min_length) {
            throw new ReviewValidationException(sprintf('Written feedback must be at least %d characters.', $this->settings->review_min_length));
        }

        if ($length > $this->settings->review_max_length) {
            throw new ReviewValidationException(sprintf('Written feedback must be at most %d characters.', $this->settings->review_max_length));
        }
    }

    /**
     * @param  list<string>  $tagKeys
     * @return list<array{key: string, label: string}>
     *
     * @throws ReviewValidationException
     */
    private function resolveTags(array $tagKeys, LessonReviewEligibilityMode $mode): array
    {
        $unique = array_values(array_unique($tagKeys));

        if ($unique === []) {
            return [];
        }

        if (count($unique) > $this->settings->review_max_tags) {
            throw new ReviewValidationException(sprintf('A submission may select at most %d tags.', $this->settings->review_max_tags));
        }

        $tags = ReviewTag::query()
            ->active()
            ->applicableTo($mode)
            ->whereIn('key', $unique)
            ->get();

        if ($tags->count() !== count($unique)) {
            throw new ReviewValidationException('One or more selected tags are invalid, inactive, or not allowed for this review type.');
        }

        return $tags->map(fn (ReviewTag $tag): array => $tag->snapshot())->all();
    }

    /** @return array<string, mixed> */
    private function ratingSettingsSnapshot(): array
    {
        return [
            'rating_min' => $this->settings->rating_min,
            'rating_max' => $this->settings->rating_max,
            'written_review_required' => $this->settings->written_review_required,
            'review_min_length' => $this->settings->review_min_length,
            'review_max_length' => $this->settings->review_max_length,
            'rating_dimensions_enabled' => $this->settings->rating_dimensions_enabled,
            'review_max_tags' => $this->settings->review_max_tags,
        ];
    }
}
