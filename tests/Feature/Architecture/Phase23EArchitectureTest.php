<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Guards the KYC-document-storage boundary: KYC documents stay on the local disk,
 * the Filament section is policy-gated (not raw upload fields), and no
 * frontend instructor surface ever exposes a document URL.
 */
final class Phase23EArchitectureTest extends TestCase
{
    public function test_kyc_collections_remain_on_the_local_disk(): void
    {
        $model = file_get_contents(app_path('Models/UserProfile.php'));
        $this->assertIsString($model);

        // Five of the six KYC collections are registered via a loop over
        // this literal array, immediately followed by useDisk('local') —
        // this is a structural check, not a per-collection regex, since
        // the collection name never appears next to the useDisk() call.
        $this->assertStringContainsString(
            "foreach (['government_id', 'address_proof', 'education_certificate', 'teaching_certificate', 'resume'] as \$collection) {\n            \$this->addMediaCollection(\$collection)\n                ->useDisk('local')",
            $model,
        );

        $this->assertStringContainsString(
            "addMediaCollection('introduction_video')\n            ->useDisk('local')",
            $model,
        );

        // Public collections must be explicit too, not left
        // dependent on the package's disk_name default.
        $this->assertStringContainsString("addMediaCollection('avatar')\n            ->useDisk('public')", $model);
        $this->assertStringContainsString("addMediaCollection('cover')\n            ->useDisk('public')", $model);
    }

    public function test_user_form_no_longer_exposes_raw_kyc_upload_fields(): void
    {
        $form = file_get_contents(app_path('Filament/Resources/Users/Schemas/UserForm.php'));
        $this->assertIsString($form);

        foreach (['government_id', 'address_proof', 'education_certificate', 'teaching_certificate', 'resume'] as $collection) {
            $this->assertStringNotContainsString(
                "SpatieMediaLibraryFileUpload::make('{$collection}')",
                $form,
                "{$collection} must no longer be a raw upload/preview field — see InstructorDocumentViewer.",
            );
        }

        $this->assertStringContainsString('InstructorDocumentViewer', $form);
    }

    public function test_verification_documents_section_is_gated_by_the_document_policy(): void
    {
        $form = file_get_contents(app_path('Filament/Resources/Users/Schemas/UserForm.php'));
        $this->assertIsString($form);

        $sectionStart = strpos($form, "Section::make('Verification Documents')");
        $this->assertNotFalse($sectionStart, 'Verification Documents section not found.');

        $sectionSnippet = substr($form, $sectionStart, 2000);

        $this->assertStringContainsString('->visible(', $sectionSnippet);
        $this->assertStringContainsString('instructor.viewDocuments', $sectionSnippet);
    }

    public function test_frontend_instructor_surfaces_never_expose_a_document_url(): void
    {
        $offenders = [];

        $paths = [
            app_path('Livewire/Frontend/Instructor'),
            resource_path('views/livewire/frontend/instructor'),
            resource_path('views/instructor'),
        ];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            foreach ($this->filesUnder($path) as $file) {
                $contents = file_get_contents($file);

                if ($contents === false) {
                    continue;
                }

                foreach (['getUrl(', 'temporaryUrl(', 'Storage::url(', "Storage::disk('local')->url("] as $forbidden) {
                    if (str_contains($contents, $forbidden)) {
                        $offenders[] = "{$file} :: {$forbidden}";
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'The frontend onboarding wizard must only expose uploaded/not-uploaded booleans, never a document URL.');
    }

    public function test_download_controller_never_uses_a_raw_storage_url_helper(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/InstructorDocumentDownloadController.php'));
        $this->assertIsString($controller);

        $this->assertStringNotContainsString('->getUrl(', $controller);
        $this->assertStringNotContainsString('->temporaryUrl(', $controller);
        $this->assertStringNotContainsString('Storage::disk($media->disk)->url(', $controller);
        $this->assertStringContainsString('Gate::authorize(', $controller);
    }

    /** @return list<string> */
    private function filesUnder(string $directory): array
    {
        $files = [];

        /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $iterator */
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
