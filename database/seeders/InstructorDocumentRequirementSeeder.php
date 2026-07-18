<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\InstructorDocumentRequirement;
use Illuminate\Database\Seeder;

/**
 * Seeds the exact current behavior of the now-removed
 * InstructorOnboardingService::REQUIRED_DOCUMENT_COLLECTIONS constant —
 * all five as required=true, matching what every existing instructor
 * has always had to provide. Deliberately does NOT match the Phase 23G
 * prompt's illustrative example rows verbatim (which showed
 * teaching_certificate as required=false) — that would silently loosen
 * an existing requirement, a real behavior change outside this phase's
 * "preserve existing onboarding" scope. `introduction_video` is
 * intentionally not seeded here — it remains the hardcoded optional
 * profile-media item it always was (InstructorOnboardingService::
 * PROFILE_MEDIA_COLLECTIONS), not a KYC verification document.
 */
class InstructorDocumentRequirementSeeder extends Seeder
{
    private const array DOCUMENT_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    private const array REQUIREMENTS = [
        ['collection_name' => 'government_id', 'label' => 'Government ID', 'sort_order' => 1],
        ['collection_name' => 'address_proof', 'label' => 'Address Proof', 'sort_order' => 2],
        ['collection_name' => 'education_certificate', 'label' => 'Education Certificate', 'sort_order' => 3],
        ['collection_name' => 'teaching_certificate', 'label' => 'Teaching Certificate', 'sort_order' => 4],
        ['collection_name' => 'resume', 'label' => 'Resume', 'sort_order' => 5],
    ];

    public function run(): void
    {
        foreach (self::REQUIREMENTS as $requirement) {
            InstructorDocumentRequirement::query()->firstOrCreate(
                ['collection_name' => $requirement['collection_name']],
                [
                    'label' => $requirement['label'],
                    'required' => true,
                    'accepted_mime_types' => self::DOCUMENT_MIME_TYPES,
                    'max_size_kb' => 4096,
                    'active' => true,
                    'sort_order' => $requirement['sort_order'],
                ],
            );
        }
    }
}
