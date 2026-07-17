<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Learning;

use App\Reporting\DTOs\Operations\LabeledCountRow;

/**
 * Phase 18F — Homework analytics. Homework lifecycle is
 * Pending → Submitted → Graded (`HomeworkStatus`). Submission and
 * grading are distinct: `submitted_at` is an authoritative event
 * timestamp; Graded is a current state with NO stored grading
 * timestamp, so graded figures are as-of-now only and there is no
 * period-scoped "reviewed in period" and no average review time
 * (§7.5 gate failed — documented limitation).
 *
 * Overdue uses the domain's own semantics (the homework model's
 * `scopeOverdue`): status Pending AND `due_at` in the past — an
 * as-of-now state, never mixed with period events. "Submitted late" is a period event:
 * `submitted_at` in period AND after `due_at`.
 *
 * Rates (§7.4): denominator = assignments whose `due_at` fell inside
 * the period AND has already elapsed (observation complete); numerator
 * for `submissionRate` = those submitted at any time, for
 * `onTimeSubmissionRate` = those submitted at or before `due_at`.
 * Both are null (never 0%) at zero denominator. One assignment has at
 * most one submission in V1 (SubmitHomeworkAction rejects
 * resubmission), so assignment count and submission count cannot
 * diverge per assignment.
 *
 * `bySubjectText` groups the assignment's free-text `subject` column —
 * homework has no subject foreign key; the label is
 * assignment-declared, not the curated Subject taxonomy.
 *
 * @param  array<string, int>  $currentByStatus  HomeworkStatus::value => count
 * @param  list<LabeledCountRow>  $byTeacher  assignment count per instructor in period
 * @param  list<LabeledCountRow>  $bySubjectText
 */
final readonly class HomeworkAnalyticsData
{
    public function __construct(
        public int $assignedInPeriod,
        public int $submittedInPeriod,
        public int $submittedLateInPeriod,
        public int $currentlyOverdue,
        public int $dueElapsedInPeriod,
        public ?float $submissionRate,
        public ?float $onTimeSubmissionRate,
        public array $currentByStatus,
        public int $gradedCurrent,
        public int $linkedToBookings,
        public int $withoutBookingLink,
        public array $byTeacher,
        public array $bySubjectText,
    ) {}
}
