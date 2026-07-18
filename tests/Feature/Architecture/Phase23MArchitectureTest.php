<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Guards the Phase 23M boundary: vacation mode is a self-service entry
 * point into InstructorOnboardingService's existing setVacation()/
 * resumeFromVacation() lifecycle transitions — no parallel vacation
 * domain, no direct profile writes from the UI, no InstructorStatus
 * enum case changes, and admin lifecycle actions untouched.
 */
final class Phase23MArchitectureTest extends TestCase
{
    public function test_no_duplicate_vacation_service_was_created(): void
    {
        $this->assertFileDoesNotExist(app_path('Services/Instructor/InstructorVacationService.php'));
        $this->assertFileDoesNotExist(app_path('Livewire/Frontend/Instructor/VacationManager.php'));
        $this->assertFileDoesNotExist(app_path('Services/Instructor/InstructorAvailabilityStatusService.php'));
        $this->assertFileDoesNotExist(app_path('Services/Instructor/TeacherLeaveService.php'));
        $this->assertFileDoesNotExist(app_path('Services/Instructor/InstructorVacationDashboardService.php'));
    }

    public function test_vacation_component_never_writes_to_the_profile_directly(): void
    {
        $component = file_get_contents(app_path('Livewire/Frontend/Instructor/VacationModeManager.php'));
        $this->assertIsString($component);

        $this->assertStringNotContainsString('->profile->update(', $component);
        $this->assertStringNotContainsString('->profile()->update(', $component);
        $this->assertStringNotContainsString('UserProfile::', $component);
        // A read (===) is expected (Archived gate); a direct assignment is not.
        $this->assertStringNotContainsString('instructor_status = ', $component);

        // Every mutation is delegated to the existing lifecycle service.
        $this->assertStringContainsString('InstructorOnboardingService', $component);
        $this->assertStringContainsString('$onboarding->setVacation(', $component);
        $this->assertStringContainsString('$onboarding->resumeFromVacation(', $component);
    }

    public function test_vacation_is_always_self_scoped_never_a_client_supplied_target(): void
    {
        $component = file_get_contents(app_path('Livewire/Frontend/Instructor/VacationModeManager.php'));
        $this->assertIsString($component);

        // Both arguments to every lifecycle call come from auth()->user()
        // — no route parameter, request input, or public property ever
        // names which instructor is being acted on.
        $this->assertStringContainsString('setVacation($user, $user)', $component);
        $this->assertStringContainsString('resumeFromVacation($user, $user)', $component);
        $this->assertStringNotContainsString('request(', $component);
        $this->assertStringNotContainsString('$this->instructorId', $component);
    }

    public function test_instructor_status_enum_cases_are_unchanged(): void
    {
        $enum = file_get_contents(app_path('Enums/InstructorStatus.php'));
        $this->assertIsString($enum);

        foreach (['Draft', 'Submitted', 'UnderReview', 'DocumentsPending', 'InterviewRequired', 'Approved', 'Active', 'Vacation', 'Suspended', 'Archived', 'Rejected'] as $case) {
            $this->assertStringContainsString("case {$case} = ", $enum);
        }

        // Exactly 11 cases — no new case was added.
        $this->assertSame(11, preg_match_all('/^\s+case \w+ = /m', $enum));
    }

    public function test_onboarding_service_lifecycle_rules_are_unchanged(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorOnboardingService.php'));
        $this->assertIsString($service);

        // setVacation()/resumeFromVacation() still guard the exact same
        // allowed-from transitions as before this phase.
        $this->assertStringContainsString('[InstructorStatus::Active],
            InstructorStatus::Vacation,', $service);
        $this->assertStringContainsString('[InstructorStatus::Vacation],
            InstructorStatus::Active,', $service);

        // The admin permission constant is untouched and still used for
        // non-self actors — no permission was removed.
        $this->assertStringContainsString("VACATION_PERMISSION = 'instructor.lifecycle.manage-vacation'", $service);
        $this->assertStringContainsString('authorizeSelfOrLifecyclePermission', $service);
    }

    public function test_availability_page_only_shows_a_banner_no_duplicate_vacation_controls(): void
    {
        $view = file_get_contents(resource_path('views/livewire/frontend/instructor/availability-manager.blade.php'));
        $this->assertIsString($view);

        $this->assertStringContainsString('Vacation mode is enabled.', $view);
        $this->assertStringNotContainsString('wire:click="enableVacation"', $view);
        $this->assertStringNotContainsString('wire:click="resumeTeaching"', $view);
    }

    public function test_dashboard_widget_reuses_onboarding_status_already_computed_by_the_dashboard_service(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorDashboardService.php'));
        $this->assertIsString($service);

        // No vacation-specific method/query was added to the dashboard
        // service this phase — the dashboard view reuses $onboarding['status'],
        // which InstructorOnboardingService::progress() already returned
        // before Phase 23M.
        $this->assertStringNotContainsString('function vacation', $service);
        $this->assertStringNotContainsString('InstructorVacation', $service);
        $this->assertSame(1, substr_count($service, 'private function earnings('));

        $view = file_get_contents(resource_path('views/livewire/frontend/instructor/dashboard-overview.blade.php'));
        $this->assertIsString($view);
        $this->assertStringContainsString("\$onboarding['status'] === \\App\\Enums\\InstructorStatus::Vacation", $view);
    }

    public function test_booking_eligibility_was_not_modified_vacation_already_excluded(): void
    {
        // InstructorStatus::bookable() — the set booking eligibility
        // reads everywhere — is untouched; only a new, additive
        // publiclyVisible() method was introduced for profile visibility.
        $enum = file_get_contents(app_path('Enums/InstructorStatus.php'));
        $this->assertIsString($enum);
        $this->assertStringContainsString('public static function bookable(): array', $enum);
        $this->assertStringContainsString('return [self::Approved, self::Active];', $enum);
        $this->assertStringContainsString('public static function publiclyVisible(): array', $enum);
    }
}
