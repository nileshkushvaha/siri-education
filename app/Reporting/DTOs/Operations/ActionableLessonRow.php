<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Operations;

use Carbon\CarbonImmutable;

/**
 * One row of the "lessons in the selected period" actionable table.
 * `studentLabel` is already masked/unmasked by the service according
 * to `ReportAccessContext` — the Filament view never re-decides this.
 * `bookingViewUrl`/`lessonViewUrl` are null when the current
 * administrator is not authorized to open that specific record — a
 * null value must render as plain text, never a link.
 */
final readonly class ActionableLessonRow
{
    public function __construct(
        public string $bookingId,
        public ?string $lessonId,
        public string $bookingReference,
        public CarbonImmutable $scheduledAtUtc,
        public string $bookingTypeLabel,
        public string $studentLabel,
        public string $instructorLabel,
        public ?string $subjectLabel,
        public string $bookingStatusLabel,
        public ?string $lessonStatusLabel,
        public ?string $lessonOutcomeLabel,
        public ?string $meetingStatusLabel,
        public ?string $bookingViewUrl,
        public ?string $lessonViewUrl,
    ) {}
}
