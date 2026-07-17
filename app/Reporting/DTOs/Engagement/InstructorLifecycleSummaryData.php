<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Engagement;

/**
 * Phase 18D — instructor lifecycle summary. `byStatus` is the CURRENT
 * `user_profiles.instructor_status` (all 11 lifecycle cases, none
 * collapsed). `approvalsInPeriod` counts structured audit events
 * (`activity_log.event = 'application_approved'`, log name
 * `instructor`) — never `instructor_reviewed_at`, which is overwritten
 * by every later admin transition and therefore cannot serve as an
 * approval timestamp.
 *
 * @param  array<string, int>  $byStatus  keyed by InstructorStatus::value
 */
final readonly class InstructorLifecycleSummaryData
{
    public function __construct(
        public int $total,
        public array $byStatus,
        public int $newAccountsInPeriod,
        public int $applicationsSubmittedInPeriod,
        public int $approvalsInPeriod,
    ) {}
}
