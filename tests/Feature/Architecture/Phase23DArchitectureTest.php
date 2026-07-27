<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Guards the instructor-lifecycle-transitions boundary: all six lifecycle transitions
 * (activate/vacation/resume/suspend/archive/interview-required) live
 * only inside InstructorOnboardingService, and the new Filament actions
 * call that service rather than writing to the model directly.
 */
final class Phase23DArchitectureTest extends TestCase
{
    public function test_only_the_established_authorities_assign_instructor_status_directly(): void
    {
        $allowed = [
            app_path('Services/Instructor/InstructorOnboardingService.php'),
            // Pre-existing admin "Force Approve" override — predates
            // and is unrelated to the six lifecycle transitions.
            app_path('Filament/Resources/Users/Pages/EditUser.php'),
        ];

        $offenders = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            if (in_array($file, $allowed, true)) {
                continue;
            }

            $contents = file_get_contents($file);

            if ($contents !== false && preg_match('/[\'"]instructor_status[\'"]\s*=>\s*InstructorStatus::(?!class)/', $contents) === 1) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'Only InstructorOnboardingService (and the pre-existing admin override) may assign instructor_status.');
    }

    public function test_no_new_filament_action_writes_the_model_directly(): void
    {
        $editUser = file_get_contents(app_path('Filament/Resources/Users/Pages/EditUser.php'));
        $this->assertIsString($editUser);

        foreach ([
            'activateInstructorAction',
            'startInstructorVacationAction',
            'resumeInstructorFromVacationAction',
            'suspendInstructorAction',
            'archiveInstructorAction',
            'markInstructorInterviewRequiredAction',
        ] as $method) {
            $body = $this->methodBody($editUser, $method);

            $this->assertNotNull($body, "Could not locate method {$method}() in EditUser.php.");
            $this->assertStringNotContainsString('->update(', $body, "{$method}() must call InstructorOnboardingService, not \$model->update().");
            $this->assertStringContainsString('InstructorOnboardingService::class', $body, "{$method}() must call InstructorOnboardingService.");
        }
    }

    public function test_assign_role_instructor_only_happens_inside_onboarding_service(): void
    {
        $allowed = [
            app_path('Services/Instructor/InstructorOnboardingService.php'),
        ];

        $offenders = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            if (in_array($file, $allowed, true)) {
                continue;
            }

            $contents = file_get_contents($file);

            if ($contents !== false && str_contains($contents, "assignRole('instructor')")) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, "assignRole('instructor') must only happen inside InstructorOnboardingService.");
    }

    public function test_no_duplicate_lifecycle_service_was_created(): void
    {
        $this->assertFileDoesNotExist(app_path('Services/Instructor/InstructorStatusService.php'));
        $this->assertFileDoesNotExist(app_path('Services/Instructor/InstructorLifecycleService.php'));
    }

    public function test_new_lifecycle_transitions_are_concurrency_guarded(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorOnboardingService.php'));
        $this->assertIsString($service);

        foreach (['activate', 'setVacation', 'resumeFromVacation', 'suspend', 'archive', 'markInterviewRequired'] as $method) {
            $body = $this->methodBody($service, $method);
            $this->assertNotNull($body, "Could not locate method {$method}() in InstructorOnboardingService.php.");
            $this->assertStringContainsString('transitionStatus(', $body, "{$method}() must go through the lockForUpdate()-guarded transitionStatus() helper.");
        }
    }

    /** Extracts a method's body by brace-matching from its `function name(` declaration — good enough for these flat, non-nested-closure-heavy methods. */
    private function methodBody(string $contents, string $method): ?string
    {
        if (preg_match('/function\s+'.preg_quote($method, '/').'\s*\([^)]*\)[^{]*\{/', $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $start = $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        $pos = $start;
        $length = strlen($contents);

        while ($pos < $length && $depth > 0) {
            $char = $contents[$pos];

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
            }

            $pos++;
        }

        return substr($contents, $start, $pos - $start - 1);
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
