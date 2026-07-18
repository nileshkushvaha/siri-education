<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\InstructorEligibilityServiceInterface;
use App\DTOs\Instructor\InstructorEligibilityResult;
use App\Models\User;
use App\Services\AuditTrailService;

/**
 * Shared gate in front of the only two places that may call
 * InstructorOnboardingService::start(): InstructorOnboardingController
 * (the retained POST /dashboard/instructor/start route) and
 * OnboardingWizard's own Livewire start() action. Neither caller may
 * invoke InstructorOnboardingService::start() without going through
 * this gate first — see Phase23CArchitectureTest.
 *
 * Not a second eligibility decision-maker: the eligibility verdict
 * itself always comes from InstructorEligibilityServiceInterface. This
 * class only (a) skips the check entirely for a user resuming an
 * application that already exists — otherwise every "Continue" click
 * from a Draft/Submitted/etc. applicant would be wrongly re-evaluated
 * and rejected as "already an instructor" — and (b) records the
 * intent-started audit event exactly once, on a genuine first attempt.
 */
final class InstructorApplicationStart
{
    public static function attempt(User $user, string $source): InstructorEligibilityResult
    {
        $profile = $user->profile;

        if ($profile?->instructor_status !== null) {
            // Resuming an existing application is not a new eligibility
            // decision — InstructorOnboardingService::start() itself is
            // already a safe no-op for these statuses (see its own
            // isSubmittableStatus()-independent firstOrCreate() guard).
            return InstructorEligibilityResult::eligible();
        }

        app(AuditTrailService::class)->logUser(
            $user,
            'instructor',
            'instructor_application_intent_started',
            'Instructor application intent captured',
            $user,
            ['source' => $source],
        );

        return app(InstructorEligibilityServiceInterface::class)->evaluate($user);
    }
}
