<?php

declare(strict_types=1);

namespace App\Reviews\DTOs;

/** One configured review tag and how many of the instructor's eligible published reviews selected it. Never a student-authored free-text tag — the tag catalog is admin-curated. */
final readonly class FeedbackTagCountData
{
    public function __construct(
        public string $key,
        public string $label,
        public int $count,
    ) {}
}
