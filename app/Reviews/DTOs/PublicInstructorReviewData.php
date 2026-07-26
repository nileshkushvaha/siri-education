<?php

declare(strict_types=1);

namespace App\Reviews\DTOs;

use App\Models\LessonReview;
use App\Reviews\Enums\ReviewableLessonType;
use App\Reviews\Support\PublicReviewerIdentity;
use App\Reviews\Support\PublicReviewVerification;
use Carbon\CarbonImmutable;

/**
 * One public-facing review card. The exclusive shape a public profile
 * view is ever handed — an Eloquent `LessonReview` (or its
 * eligibility/lesson/student relations) never reaches a public Blade
 * view directly, so there is no path by which a moderation reason,
 * student id, or booking/lesson reference can leak through.
 *
 * `id` is the review's own primary key only — needed so the
 * user-facing "Report Review" action can target the exact review server-
 * side; it carries no student/booking/moderation information itself and
 * every write path re-validates reportability from the database, never
 * trusting this value.
 */
final readonly class PublicInstructorReviewData
{
    /**
     * @param  array<string, ?int>  $dimensionRatings  keyed by dimension name
     * @param  list<array{key: string, label: string}>  $tags
     */
    public function __construct(
        public string $id,
        public string $reviewerLabel,
        public int $overallRating,
        public array $dimensionRatings,
        public ?string $content,
        public array $tags,
        public CarbonImmutable $submittedAt,
        public bool $verifiedLesson,
        public ?string $lessonType,
    ) {}

    public static function fromReview(LessonReview $review, string $identityMode): self
    {
        $lessonType = $review->eligibility?->lesson_type;

        return new self(
            id: $review->id,
            reviewerLabel: PublicReviewerIdentity::label($review->student, $identityMode),
            overallRating: $review->overall_rating,
            dimensionRatings: [
                'teaching_quality' => $review->teaching_quality_rating,
                'communication' => $review->communication_rating,
                'punctuality' => $review->punctuality_rating,
                'preparedness' => $review->preparedness_rating,
                'learning_value' => $review->learning_value_rating,
            ],
            content: $review->content,
            tags: $review->tags ?? [],
            submittedAt: CarbonImmutable::instance($review->submitted_at),
            verifiedLesson: PublicReviewVerification::isVerified($review),
            lessonType: $lessonType?->value,
        );
    }

    public function isDemo(): bool
    {
        return $this->lessonType === ReviewableLessonType::Demo->value;
    }
}
