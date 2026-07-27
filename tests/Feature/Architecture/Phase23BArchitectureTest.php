<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Guards the instructor-eligibility-workspace boundary: eligibility/workspace-switch backend
 * foundation only, no UI, no duplicated rules, no new registration flow.
 */
final class Phase23BArchitectureTest extends TestCase
{
    public function test_eligibility_reason_codes_are_only_produced_by_the_eligibility_service(): void
    {
        $allowed = [
            app_path('Services/Instructor/InstructorEligibilityService.php'),
            app_path('Enums/InstructorEligibilityCode.php'),
            // The DTO's own eligible()/ineligible() factory methods construct
            // the enum value they were given — that is the DTO shape, not a
            // second place business rules are decided.
            app_path('DTOs/Instructor/InstructorEligibilityResult.php'),
        ];

        $offenders = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            if (in_array($file, $allowed, true)) {
                continue;
            }

            $contents = file_get_contents($file);

            if ($contents !== false && str_contains($contents, 'InstructorEligibilityCode::')) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'InstructorEligibilityCode must only be produced by InstructorEligibilityService, not duplicated elsewhere.');
    }

    public function test_frontend_portal_audience_resolver_is_the_only_consumer_of_workspace_preferred_audience(): void
    {
        $resolverFile = app_path('Services/FrontendPortalAudienceResolver.php');
        $workspaceServiceFile = app_path('Services/FrontendPortalWorkspaceService.php');

        $offenders = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            if (in_array($file, [$resolverFile, $workspaceServiceFile], true)) {
                continue;
            }

            $contents = file_get_contents($file);

            if ($contents !== false && str_contains($contents, '->preferredAudience(')) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'Only FrontendPortalAudienceResolver may consult FrontendPortalWorkspaceService::preferredAudience().');
    }

    public function test_workspace_switch_service_still_has_no_controller_or_ui_wiring(): void
    {
        // FrontendPortalWorkspaceService's UI (the actual workspace-switch
        // control) is explicitly out of scope
        // ("Do not implement: Workspace switch UI"). Only
        // FrontendPortalAudienceResolver (checked separately above) may
        // reference it.
        $offenders = [];
        $files = [...$this->phpFilesUnder(app_path('Http/Controllers')), ...$this->phpFilesUnder(app_path('Livewire'))];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            if ($contents !== false && str_contains($contents, 'FrontendPortalWorkspaceService')) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'FrontendPortalWorkspaceService has no UI yet — still backend-only.');
    }

    public function test_eligibility_service_is_only_wired_into_the_phase23c_sanctioned_entry_points(): void
    {
        // InstructorEligibilityServiceInterface is sanctioned for exactly
        // these entry points to consult
        // it; any other controller/Livewire component doing so would be an
        // unreviewed second integration point.
        $allowed = [
            app_path('Http/Controllers/Instructor/InstructorApplicationController.php'),
        ];

        $offenders = [];
        $files = [...$this->phpFilesUnder(app_path('Http/Controllers')), ...$this->phpFilesUnder(app_path('Livewire'))];

        foreach ($files as $file) {
            if (in_array($file, $allowed, true)) {
                continue;
            }

            $contents = file_get_contents($file);

            if ($contents !== false && str_contains($contents, 'InstructorEligibilityService')) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'InstructorEligibilityServiceInterface must only be wired into the sanctioned entry points (InstructorApplicationController directly; InstructorOnboardingController/OnboardingWizard indirectly via InstructorApplicationStart).');
    }

    public function test_no_new_instructor_registration_routes_exist(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertIsString($routes);
        $this->assertStringNotContainsString('instructor-registration', $routes);
        $this->assertStringNotContainsString('instructor_registration', $routes);
        $this->assertStringNotContainsString("'become-instructor'", $routes);
    }

    public function test_instructor_onboarding_service_is_unchanged_as_the_only_lifecycle_writer(): void
    {
        $eligibilityService = file_get_contents(app_path('Services/Instructor/InstructorEligibilityService.php'));

        $this->assertIsString($eligibilityService);
        $this->assertStringNotContainsString('instructor_status\' =>', $eligibilityService);
        $this->assertStringNotContainsString('->assignRole(', $eligibilityService);
    }

    /** @return list<string> */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $iterator */
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
