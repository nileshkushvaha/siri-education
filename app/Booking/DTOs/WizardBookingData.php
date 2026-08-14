<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use Carbon\CarbonImmutable;

/**
 * An authenticated student's wizard booking request (renamed from the
 * pre-authenticated-only "guest booking" DTO). The
 * student identity always comes from the authenticated session, never
 * from this payload. Most wizard bookings have no teacher and the
 * assignment engine picks one; profile-launched bookings may lock a
 * specific teacher while still using the same eligibility and
 * availability checks.
 */
final readonly class WizardBookingData
{
    public function __construct(
        public string $typeKey,
        public string $subject,
        public int $grade,
        public CarbonImmutable $startsAt,
        public string $timezone,
        public ?string $notes = null,
        public ?int $teacherId = null,
        /**
         * Phase 3/3.1 — set only by the country-aware Free Demo academic
         * flow (BookingWizard's education-system/level/subject/
         * curriculum phases). $subject/$grade above remain populated
         * (for meta/legacy-compat and TeacherSubject matching) even in
         * this flow — see WizardBookingService::resolveAcademicContext()
         * for how these raw, UNTRUSTED ids are re-resolved and
         * validated server-side before ever reaching a Booking.
         * educationSystemLevelId (not academicLevelId — students never
         * choose the broad AcademicLevel band directly) implies both
         * academic_level_id and normalized_grade once resolved.
         */
        public ?string $educationSystemId = null,
        public ?string $educationSystemLevelId = null,
        public ?string $subjectId = null,
        public ?string $curriculumId = null,
        /**
         * Phase 4D — the package entitlement the student EXPLICITLY
         * chose to fund this booking with, raw and UNTRUSTED. Null
         * means "pay normally", which stays the default: owning a
         * compatible package never forces its use (§31).
         *
         * WizardBookingService re-validates this against the student's
         * own ownership, the selected instructor, and the resolved
         * academic context before it can reach a Booking — a forged
         * UUID gets no further than that check (§40).
         */
        public ?string $packageEntitlementId = null,
    ) {}
}
