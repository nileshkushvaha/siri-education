<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Earnings\Enums\CompensationPayBasis;
use App\Models\InstructorCompensationAgreement;
use App\Models\InstructorCompensationOverride;
use App\Models\User;
use DateTimeInterface;

interface InstructorCompensationAgreementServiceInterface
{
    /**
     * Draft a new agreement. The amount is the administrator's internal
     * decision — never derived from student price or automatically from
     * experience/ratings. Periodic bases must start on their period
     * boundary (day/ISO-week/month) in the agreement timezone.
     */
    public function createDraft(
        User $admin,
        User $instructor,
        CompensationPayBasis $basis,
        int $amountMinor,
        string $currencyCode,
        string $timezone,
        DateTimeInterface $effectiveFrom,
        string $internalReason,
        ?string $notes = null,
    ): InstructorCompensationAgreement;

    /** Hourly agreements only, draft/scheduled only. */
    public function addOverride(
        InstructorCompensationAgreement $agreement,
        User $admin,
        ?string $subjectId,
        ?string $academicLevelId,
        ?int $durationMinutes,
        int $amountMinor,
    ): InstructorCompensationOverride;

    /** Draft/scheduled only. */
    public function removeOverride(InstructorCompensationOverride $override, User $admin): void;

    public function schedule(InstructorCompensationAgreement $agreement, User $admin): InstructorCompensationAgreement;

    /** Freezes financial terms; enforces single-active + no overlap under the owner lock. */
    public function activate(InstructorCompensationAgreement $agreement, User $admin, string $reason): InstructorCompensationAgreement;

    /** Sets the effective end (boundary-validated for periodic bases). */
    public function end(InstructorCompensationAgreement $agreement, User $admin, DateTimeInterface $effectiveUntil, string $reason): InstructorCompensationAgreement;

    public function cancel(InstructorCompensationAgreement $agreement, User $admin, ?string $reason = null): InstructorCompensationAgreement;

    /**
     * Rate change: ends the old agreement at the new effective date and
     * creates the successor (version + 1, supersedes link). The old
     * agreement and every historical earning stay untouched.
     */
    public function replace(
        InstructorCompensationAgreement $agreement,
        User $admin,
        CompensationPayBasis $basis,
        int $amountMinor,
        string $currencyCode,
        DateTimeInterface $effectiveFrom,
        string $reason,
        ?string $notes = null,
    ): InstructorCompensationAgreement;

    /**
     * Lazily settle time-based transitions for one instructor: expire
     * active agreements whose window closed, promote scheduled ones
     * whose window opened. System-actor audited. Must run inside the
     * caller's transaction with the instructor row locked.
     */
    public function syncLifecycle(int $instructorId, DateTimeInterface $now): void;
}
