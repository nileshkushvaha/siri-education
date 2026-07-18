<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

final class Phase20BPortalArchitectureTest extends TestCase
{
    public function test_dashboard_blade_has_no_role_resolution_or_inverted_condition(): void
    {
        $blade = file_get_contents(resource_path('views/dashboard/index.blade.php'));

        $this->assertStringNotContainsString('hasRole(', $blade);
        $this->assertStringNotContainsString("! auth()->user()->hasRole('instructor')", $blade);
        $this->assertStringNotContainsString('InstructorOnboardingService', $blade);
    }

    public function test_controller_does_not_depend_on_instructor_services(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Dashboard/DashboardController.php'));

        $this->assertStringContainsString('FrontendPortalAudienceResolver', $controller);
        $this->assertStringNotContainsString('InstructorOnboardingService', $controller);
    }

    public function test_account_menu_is_the_only_account_navigation_definition(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/account.blade.php'));
        $sidebar = file_get_contents(resource_path('views/components/account/sidebar.blade.php'));

        $this->assertStringContainsString('$accountMenu', $layout);
        $this->assertStringContainsString('$menu', $sidebar);
        $this->assertSame(0, substr_count($layout, "'Upcoming Classes'"));
        $this->assertSame(0, substr_count($layout, "'Homework'"));
    }
}
