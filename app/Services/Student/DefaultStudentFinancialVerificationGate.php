<?php

declare(strict_types=1);

namespace App\Services\Student;

use App\Booking\Exceptions\BookingException;
use App\Contracts\StudentFinancialVerificationGate;
use App\Models\BookingType;
use App\Models\User;
use App\Settings\AuthenticationSettings;

/**
 * Phase 24R — GAP: revalidated against docs/SRS.md §2.5 ("Phone Number
 * (Optional for Version 1)") and §11.14 ("Paid Booking Eligibility Rules"
 * — "Student must be registered and verified", with no phone/mobile
 * requirement anywhere in that list). "Verified" for a student means
 * EMAIL verification throughout the SRS (§2.6/3976 "created in an
 * unverified state", §11.13's demo rule "Student email must be
 * verified", §11.39 "Only verified students may book lessons") — never
 * phone. No phone-verification model/process is described in the SRS at
 * all.
 *
 * The previous implementation required `phone_e164` + `phone_verified_at`
 * — unsupported by the SRS, and additionally impossible to satisfy in
 * this deployment: the bound PhoneOtpSender is
 * App\Services\Phone\UnavailablePhoneOtpSender, which always throws
 * ("temporarily unavailable"), so `phone_verified_at` could never
 * legitimately become non-null. That combination blocked every paid
 * booking unconditionally — a production regression, not a test-fixture
 * problem.
 *
 * This now checks the SRS-correct condition (email verification), using
 * the SAME AuthenticationSettings::email_verification_required toggle
 * App\Booking\Validation\Rules\VerifiedActiveStudentRule and the HTTP
 * middleware layer already respect, so this can never disagree with
 * them. Defense-in-depth is intentional here (same reasoning as that
 * rule's own docblock: a booking created through a different entry
 * point must not bypass checks just because it skipped the pipeline
 * that rule runs in) — this gate is the dedicated checkpoint for the two
 * booking-creation call sites (StudentBookingService, WizardBookingService)
 * specifically. Phone number remains a real, optional profile field —
 * nothing here removes it, only the hard requirement to have one before
 * paying.
 */
final class DefaultStudentFinancialVerificationGate implements StudentFinancialVerificationGate
{
    public function __construct(
        private readonly AuthenticationSettings $authSettings,
    ) {}

    public function assertEligible(User $student, BookingType $type): void
    {
        if (! $type->is_paid || ! $student->hasRole('student')) {
            return;
        }

        if ($this->authSettings->email_verification_required && ! $student->hasVerifiedEmail()) {
            throw new BookingException('Verify your email address before booking a paid lesson.');
        }
    }
}
