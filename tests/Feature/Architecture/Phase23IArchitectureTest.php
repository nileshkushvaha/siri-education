<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Guards the instructor-dashboard-onboarding boundary: onboarding-prompt visibility is a
 * service-owned rule (not computed in the Blade view), the dashboard
 * queries stay bounded, and no duplicate DTO/service was introduced.
 */
final class Phase23IArchitectureTest extends TestCase
{
    public function test_onboarding_prompt_visibility_is_decided_by_the_service_not_the_blade(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorDashboardService.php'));
        $this->assertIsString($service);
        $this->assertStringContainsString('onboardingPromptVisible', $service);

        $view = file_get_contents(resource_path('views/livewire/frontend/instructor/dashboard-overview.blade.php'));
        $this->assertIsString($view);

        // The Blade must consult the pre-computed flag, never recompute a
        // percentage/next_action-based visibility rule itself.
        $this->assertStringContainsString("\$onboarding['show_prompt']", $view);
        $this->assertStringNotContainsString("(\$onboarding['percentage'] ?? 0) < 100", $view);
    }

    public function test_dashboard_service_uses_bounded_queries_for_upcoming_lessons(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorDashboardService.php'));
        $this->assertIsString($service);

        $this->assertStringContainsString('->limit(4)', $service);
        // The old pattern loaded everything then filtered/took in PHP —
        // must not reappear.
        $this->assertStringNotContainsString('$upcoming->count()', $service);
        $this->assertStringNotContainsString('$upcoming->filter(', $service);
        $this->assertStringNotContainsString('$upcoming->take(', $service);
    }

    public function test_no_duplicate_instructor_dashboard_dto_or_service_was_created(): void
    {
        // This extends the existing App\DTOs\InstructorDashboard\InstructorDashboardData
        // rather than introducing a second one at App\DTOs\Instructor\InstructorDashboardData
        // with the same class basename.
        $this->assertFileDoesNotExist(app_path('DTOs/Instructor/InstructorDashboardData.php'));
        $this->assertFileExists(app_path('DTOs/InstructorDashboard/InstructorDashboardData.php'));

        $matches = [];

        foreach ($this->phpFilesUnder(app_path('Services/Instructor')) as $file) {
            if (str_contains(basename($file), 'InstructorDashboardService')) {
                $matches[] = $file;
            }
        }

        $this->assertCount(1, $matches);
    }

    public function test_dashboard_notification_count_reuses_the_shared_count_not_a_second_query(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorDashboardService.php'));
        $this->assertIsString($service);

        // summary() takes the already-computed count as a parameter — it
        // must never call unreadNotifications() itself.
        $this->assertStringNotContainsString('unreadNotifications()', $service);
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
