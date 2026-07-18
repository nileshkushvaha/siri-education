<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Machine-readable reason codes for InstructorEligibilityService's
 * decision — never compare on the human-readable reason string.
 */
enum InstructorEligibilityCode: string
{
    case Eligible = 'eligible';
    case EmailNotVerified = 'email_not_verified';
    case AccountSuspended = 'account_suspended';
    case SchoolStudentRestricted = 'school_student_restricted';
    case AlreadyInstructor = 'already_instructor';
    case MissingEducationInformation = 'missing_education_information';
}
