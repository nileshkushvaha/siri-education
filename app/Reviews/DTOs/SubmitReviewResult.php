<?php

declare(strict_types=1);

namespace App\Reviews\DTOs;

use App\Models\LessonReview;

/** applied=false means an idempotent repeat — the existing review was returned, nothing new was written. */
final readonly class SubmitReviewResult
{
    public function __construct(
        public LessonReview $review,
        public bool $applied,
    ) {}
}
