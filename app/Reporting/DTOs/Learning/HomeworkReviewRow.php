<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Learning;

use Carbon\CarbonImmutable;

/**
 * One row of the homework attention table (currently overdue, or
 * submitted and awaiting grading). `studentLabel` is masked server-side
 * per the full-identity permission. `subjectText` is the assignment's
 * free-text subject column. There is no homework admin resource in
 * this codebase, so no drill-down URL exists — by design, not
 * omission. `submission_text`, `feedback` and `grade` are private
 * academic content and are structurally absent from this DTO.
 */
final readonly class HomeworkReviewRow
{
    public function __construct(
        public string $homeworkId,
        public string $studentLabel,
        public string $teacherLabel,
        public string $subjectText,
        public string $statusLabel,
        public CarbonImmutable $assignedAtUtc,
        public CarbonImmutable $dueAtUtc,
        public ?CarbonImmutable $submittedAtUtc,
        public int $ageDays,
    ) {}
}
