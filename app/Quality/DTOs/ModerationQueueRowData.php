<?php

declare(strict_types=1);

namespace App\Quality\DTOs;

use App\Models\LessonReview;
use App\Reviews\Support\PublicReviewerIdentity;
use Carbon\CarbonImmutable;

/**
 * One row in the admin review-moderation queue. Student identity is
 * masked through the exact same `PublicReviewerIdentity` the public
 * profile page uses — staff browsing the queue see no more of a
 * student's identity than a public visitor would. Never carries
 * booking/payment data or the review's own moderation-reason text (that
 * stays behind the existing moderation action link, not duplicated
 * here).
 */
final readonly class ModerationQueueRowData
{
    public function __construct(
        public string $reviewId,
        public int $instructorId,
        public string $instructorName,
        public string $maskedStudentLabel,
        public string $reviewMode,
        public int $overallRating,
        public CarbonImmutable $submittedAt,
        public string $status,
        /** @var list<string> */
        public array $sanitizationFlags,
        public int $reportCount,
    ) {}

    public static function fromReview(LessonReview $review, string $identityMode): self
    {
        return new self(
            reviewId: $review->id,
            instructorId: $review->instructor_id,
            instructorName: $review->instructor->name,
            maskedStudentLabel: PublicReviewerIdentity::label($review->student, $identityMode),
            reviewMode: $review->review_mode->value,
            overallRating: $review->overall_rating,
            submittedAt: CarbonImmutable::instance($review->submitted_at),
            status: $review->status->value,
            sanitizationFlags: $review->sanitization_metadata['flags'] ?? [],
            reportCount: (int) ($review->reports_count ?? $review->reports()->count()),
        );
    }
}
