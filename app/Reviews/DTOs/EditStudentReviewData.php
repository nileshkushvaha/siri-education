<?php

declare(strict_types=1);

namespace App\Reviews\DTOs;

/**
 * Raw student input for one review edit — the same shape as
 * SubmitStudentReviewData, carried unvalidated and unsanitized.
 * Validation runs against the review's OWN stored settings snapshot
 * (never current settings), and sanitization inside
 * EditStudentReviewAction. Instructor, lesson, booking, and review
 * mode are never part of this DTO — an edit can never change them.
 */
final readonly class EditStudentReviewData
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
