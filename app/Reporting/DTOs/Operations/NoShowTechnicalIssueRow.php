<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Operations;

use Carbon\CarbonImmutable;

/** One row of the "no-shows & technical issues" actionable table. */
final readonly class NoShowTechnicalIssueRow
{
    public function __construct(
        public string $lessonId,
        public string $bookingId,
        public CarbonImmutable $scheduledAtUtc,
        public string $studentLabel,
        public string $instructorLabel,
        public ?string $subjectLabel,
        public string $outcomeLabel,
        public string $lessonStatusLabel,
        public ?string $lessonViewUrl,
        public ?string $bookingViewUrl,
    ) {}
}
