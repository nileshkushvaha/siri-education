<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Operations;

/**
 * Phase 18C — meeting metrics. Date basis: `booking_meetings.created_at`
 * for `created`/`failed` (when the meeting record itself was created);
 * `bookings.starts_at` for `missingMeeting` (a confirmed booking whose
 * scheduled start falls in the period but has no `Created` meeting);
 * `lesson_attendance_records` join-evidence timestamps for the join
 * counts — a null join timestamp means "no evidence yet", counted as
 * neither joined nor absent (never inferred as a no-show).
 *
 * Deliberately excluded from this phase (documented gaps, not
 * fabricated): join-delay/attended-duration averages, meeting-provider
 * reliability scoring, and recording metadata — none has a stable
 * enough definition or reliable enough source today (see final report
 * §12 "Ratios and averages").
 */
final readonly class MeetingOperationsSummaryData
{
    public function __construct(
        public int $created,
        public int $failed,
        public int $missingMeeting,
        public int $studentJoined,
        public int $instructorJoined,
        public int $bothJoined,
        public int $technicalIssueReports,
    ) {}
}
