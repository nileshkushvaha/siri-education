<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Engagement;

use Carbon\CarbonImmutable;

/**
 * One row of the student engagement review table. `studentLabel` is
 * masked/unmasked server-side by the service according to
 * `ReportAccessContext` — never re-decided in the view. `drillDownUrl`
 * is null when the viewer lacks the existing student-view permission;
 * a null renders as plain text, never a link. No email, phone, wallet,
 * payment, or private feedback field exists on this DTO at all.
 */
final readonly class StudentEngagementRow
{
    public function __construct(
        public int $studentId,
        public string $studentLabel,
        public ?string $countryLabel,
        public string $accountStatusLabel,
        public bool $verified,
        public int $lifetimeBookingCount,
        public int $bookingsInPeriod,
        public int $completedLessonsInPeriod,
        public int $activeLearningPlanCount,
        public ?CarbonImmutable $lastQualifyingActivityUtc,
        public ?string $drillDownUrl,
    ) {}
}
