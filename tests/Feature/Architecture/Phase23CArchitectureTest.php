<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Guards the instructor-application boundary: one public application entry, no
 * duplicate registration/account-creation path, no direct instructor
 * role/status writes outside the two already-established authorities
 * (InstructorOnboardingService for lifecycle writes, the pre-existing
 * admin Force Approve override in EditUser.php).
 */
final class Phase23CArchitectureTest extends TestCase
{
    public function test_no_duplicate_instructor_registration_route_exists(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertIsString($routes);
        $this->assertStringNotContainsString('instructor-registration', $routes);
        $this->assertStringNotContainsString('instructor_registration', $routes);

        // Exactly one route creates a user via the registration POST path.
        $this->assertSame(1, substr_count($routes, "RegisterController::class, 'store'"));
    }

    public function test_only_the_established_authorities_assign_instructor_status_directly(): void
    {
        $allowed = [
            app_path('Services/Instructor/InstructorOnboardingService.php'),
            // Pre-existing admin "Force Approve" override — predates
            // and is unrelated to the public application entry.
            app_path('Filament/Resources/Users/Pages/EditUser.php'),
        ];

        $offenders = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            if (in_array($file, $allowed, true)) {
                continue;
            }

            $contents = file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            if (preg_match('/[\'"]instructor_status[\'"]\s*=>\s*InstructorStatus::(?!class)/', $contents) === 1) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'Only InstructorOnboardingService (and the pre-existing admin override) may assign instructor_status.');
    }

    public function test_only_the_two_established_callers_invoke_onboarding_service_start(): void
    {
        $allowed = [
            app_path('Http/Controllers/Instructor/InstructorOnboardingController.php'),
            app_path('Livewire/Frontend/Instructor/OnboardingWizard.php'),
            // Calls itself internally (resume/idempotent firstOrCreate guard) — not a new caller.
            app_path('Services/Instructor/InstructorOnboardingService.php'),
        ];

        $offenders = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            if (in_array($file, $allowed, true)) {
                continue;
            }

            $contents = file_get_contents($file);

            if ($contents !== false && str_contains($contents, '->start(') && str_contains($contents, 'InstructorOnboardingService')) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'InstructorOnboardingService::start() must only be called from its two established callers — new callers must go through InstructorApplicationStart::attempt() first.');
    }

    public function test_new_support_and_controller_files_never_call_onboarding_start_directly(): void
    {
        foreach ([
            app_path('Support/InstructorApplicationStart.php'),
            app_path('Support/InstructorApplicationIntent.php'),
            app_path('Http/Controllers/Instructor/InstructorApplicationController.php'),
        ] as $file) {
            $contents = file_get_contents($file);

            $this->assertIsString($contents);
            $this->assertStringNotContainsString('->onboarding->start(', $contents);
            $this->assertStringNotContainsString('assignRole(', $contents);
        }
    }

    public function test_public_landing_page_controller_never_starts_or_writes_an_application(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Instructor/InstructorApplicationController.php'));

        $this->assertIsString($controller);
        $this->assertStringNotContainsString('->start(', $controller);
        $this->assertStringNotContainsString('->update(', $controller);
        $this->assertStringNotContainsString('->save(', $controller);
    }

    public function test_only_one_instructor_onboarding_service_class_exists(): void
    {
        $matches = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            $contents = file_get_contents($file);

            if ($contents !== false && preg_match('/^final class InstructorOnboardingService\b/m', $contents) === 1) {
                $matches[] = $file;
            }
        }

        $this->assertCount(1, $matches);
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
