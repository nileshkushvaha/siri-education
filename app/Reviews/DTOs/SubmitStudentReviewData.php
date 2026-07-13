<?php

declare(strict_types=1);

namespace App\Reviews\DTOs;

/**
 * Raw student input for one review submission. Rating bounds, text
 * length, and tag validity are all enforced inside
 * SubmitLessonReviewAction — this DTO carries the input as given,
 * unvalidated and unsanitized.
 */
final readonly class SubmitStudentReviewData
{
    /** @param list<string> $tagKeys */
    public function __construct(
        public int $overallRating,
        public ?int $teachingQualityRating = null,
        public ?int $communicationRating = null,
        public ?int $punctualityRating = null,
        public ?int $preparednessRating = null,
        public ?int $learningValueRating = null,
        public ?string $content = null,
        public array $tagKeys = [],
    ) {}
}
