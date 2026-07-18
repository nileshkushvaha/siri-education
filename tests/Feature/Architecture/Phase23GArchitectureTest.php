<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Guards the Phase 23G boundary: KYC requirements are database-backed
 * (not a hardcoded constant), and `user_profiles.designation` is gone
 * from every write path, not just deprecated.
 */
final class Phase23GArchitectureTest extends TestCase
{
    public function test_required_document_collections_constant_has_no_business_usage(): void
    {
        // These three files legitimately name the removed constant in a
        // /** */ docblock only, to document what replaced it — not a
        // `self::REQUIRED_DOCUMENT_COLLECTIONS` (or similar) call site.
        $allowedToMention = [
            app_path('Services/Instructor/InstructorOnboardingService.php'),
            app_path('Services/Instructor/InstructorDocumentRequirementService.php'),
            app_path('Models/InstructorDocumentRequirement.php'),
        ];

        $offenders = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            if (in_array($file, $allowedToMention, true)) {
                continue;
            }

            $contents = file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            if (str_contains($contents, 'REQUIRED_DOCUMENT_COLLECTIONS') || str_contains($contents, 'OPTIONAL_DOCUMENT_COLLECTIONS')) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'REQUIRED_DOCUMENT_COLLECTIONS/OPTIONAL_DOCUMENT_COLLECTIONS must not be declared or used anywhere — see InstructorDocumentRequirementService.');

        // The three allowed files must only ever mention it inside a
        // docblock comment (starting with `*`), never as `self::`/`Class::`
        // executable usage.
        foreach ($allowedToMention as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents);

            foreach (preg_split('/\R/', $contents) as $line) {
                if (str_contains($line, 'REQUIRED_DOCUMENT_COLLECTIONS') || str_contains($line, 'OPTIONAL_DOCUMENT_COLLECTIONS')) {
                    $this->assertStringStartsWith('*', trim($line), "{$file}: mention of the removed constant must be inside a docblock comment, not executable code: ".trim($line));
                }
            }
        }
    }

    public function test_instructor_onboarding_service_consults_the_requirement_service_not_a_constant(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorOnboardingService.php'));
        $this->assertIsString($service);

        $this->assertStringContainsString('InstructorDocumentRequirementService', $service);
        $this->assertStringContainsString('requiredCollections()', $service);
    }

    public function test_designation_is_removed_from_every_user_profile_write_path(): void
    {
        // Checks the functional write patterns, not the bare English word —
        // this file's own explanatory comments legitimately name the
        // removed field to document why it's gone.
        $forbiddenPatterns = [
            "make('designation')",
            "'designation' =>",
            "'designation' => [\$instructorOnly",
            'name="designation"',
            'profile.designation',
        ];

        foreach ([
            app_path('Filament/Resources/Users/Schemas/UserForm.php'),
            app_path('Http/Requests/Profile/UpdateProfileRequest.php'),
            app_path('Actions/Profile/UpdateProfileAction.php'),
            resource_path('views/profile/show.blade.php'),
        ] as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents);

            foreach ($forbiddenPatterns as $pattern) {
                $this->assertStringNotContainsString($pattern, $contents, "{$file} must no longer write the removed user_profiles.designation column ({$pattern}).");
            }
        }
    }

    public function test_user_profile_model_no_longer_declares_designation_fillable(): void
    {
        $model = file_get_contents(app_path('Models/UserProfile.php'));
        $this->assertIsString($model);
        $this->assertStringNotContainsString("'designation',", $model);
    }

    public function test_instructor_document_requirement_can_never_be_deleted(): void
    {
        $model = file_get_contents(app_path('Models/InstructorDocumentRequirement.php'));
        $this->assertIsString($model);

        $this->assertStringContainsString('PreventsHardDeletion', $model);
        $this->assertStringNotContainsString('use Illuminate\Database\Eloquent\SoftDeletes;', $model);
    }

    public function test_instructor_document_requirement_resource_registers_no_delete_action(): void
    {
        $table = file_get_contents(app_path('Filament/Resources/InstructorDocumentRequirements/Tables/InstructorDocumentRequirementsTable.php'));
        $this->assertIsString($table);
        $this->assertStringNotContainsString('DeleteAction', $table);
        $this->assertStringNotContainsString('DeleteBulkAction', $table);
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
