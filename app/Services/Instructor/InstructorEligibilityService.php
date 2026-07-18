<?php

declare(strict_types=1);

namespace App\Services\Instructor;

use App\Contracts\InstructorEligibilityServiceInterface;
use App\DTOs\Instructor\InstructorEligibilityResult;
use App\Enums\InstructorEligibilityCode;
use App\Models\User;

/**
 * Single authority for instructor-application eligibility (Phase 23B).
 * Does not start, resume, or modify an application — InstructorOnboardingService
 * remains the only writer of onboarding/lifecycle state. This service only
 * answers "may this user press Start?"; nothing in this phase calls it from
 * a route yet (no public "Become Instructor" entry exists — see Phase 23A
 * audit, Phase 23C).
 */
final class InstructorEligibilityService implements InstructorEligibilityServiceInterface
{
    public function __construct(
        private readonly InstructorEducationLevelResolver $educationLevels,
    ) {}

    public function evaluate(User $user): InstructorEligibilityResult
    {
        if (! $user->hasVerifiedEmail()) {
            return InstructorEligibilityResult::ineligible(
                InstructorEligibilityCode::EmailNotVerified,
                'Please verify your email before applying to teach.',
            );
        }

        if (! $user->isActive() || $user->isBlocked()) {
            return InstructorEligibilityResult::ineligible(
                InstructorEligibilityCode::AccountSuspended,
                'Your account is not in good standing.',
            );
        }

        $profile = $user->profile;

        if ($profile?->instructor_status !== null) {
            return InstructorEligibilityResult::ineligible(
                InstructorEligibilityCode::AlreadyInstructor,
                'An instructor application already exists for this account.',
            );
        }

        $band = $this->educationLevels->resolve($user);

        if ($band === null) {
            return InstructorEligibilityResult::ineligible(
                InstructorEligibilityCode::MissingEducationInformation,
                'Add your education information before applying to teach.',
            );
        }

        if ($band->isSchoolTier()) {
            return InstructorEligibilityResult::ineligible(
                InstructorEligibilityCode::SchoolStudentRestricted,
                'Current school students cannot apply as instructors.',
            );
        }

        return InstructorEligibilityResult::eligible();
    }
}
