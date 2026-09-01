<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\InstructorEvidenceCollection;
use App\Models\InstructorDocumentRequirement;
use Illuminate\Database\Seeder;

/**
 * Seeds one requirement row per InstructorEvidenceCollection case, so
 * every evidence type an instructor can upload is administrable from
 * People → Instructors → Document Requirements instead of some of them
 * being invisible to admins.
 *
 * Driven by the enum rather than its own list: adding a case to
 * InstructorEvidenceCollection without seeding a row here used to be
 * silent, and InstructorDocumentRequirementSeederTest now fails on it.
 *
 * Two things are deliberate about the values:
 *
 *  - The five KYC documents stay required=true, matching the behavior of
 *    the removed InstructorOnboardingService::REQUIRED_DOCUMENT_COLLECTIONS
 *    constant and what every existing instructor has always had to
 *    provide. An earlier illustrative example showed teaching_certificate
 *    as optional; seeding that would silently loosen a live requirement.
 *  - introduction_video is required=true as well, so an application
 *    cannot be submitted without it. It is still profile media rather
 *    than a KYC document (InstructorOnboardingService::
 *    PROFILE_MEDIA_COLLECTIONS), which only affects where it is
 *    uploaded — being listed here is what makes it mandatory. Its mime
 *    types and size cap are the video ones registered on UserProfile's
 *    media collection, not the document set's, and match the wizard's
 *    own upload validation.
 *
 * Every row is seeded required=true, but `required` stays a per-row
 * column, not a hardcoded true: admins relax individual requirements
 * from Document Requirements, and this seeder must not fight that.
 *
 * firstOrCreate, never updateOrCreate: these rows are admin-editable, so
 * re-running the seeder must not revert a deliberate configuration
 * change. Retiring a requirement is `active = false` (the model forbids
 * hard deletion), which this seeder also leaves alone.
 */
class InstructorDocumentRequirementSeeder extends Seeder
{
    private const array DOCUMENT_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    private const array VIDEO_MIME_TYPES = ['video/mp4', 'video/webm', 'video/quicktime'];

    private const int DOCUMENT_MAX_SIZE_KB = 4096;

    private const int VIDEO_MAX_SIZE_KB = 51200;

    public function run(): void
    {
        foreach (InstructorEvidenceCollection::cases() as $index => $collection) {
            $isVideo = $collection === InstructorEvidenceCollection::IntroductionVideo;

            InstructorDocumentRequirement::query()->firstOrCreate(
                ['collection_name' => $collection->value],
                [
                    'label' => $collection->label(),
                    'required' => true,
                    'accepted_mime_types' => $isVideo ? self::VIDEO_MIME_TYPES : self::DOCUMENT_MIME_TYPES,
                    'max_size_kb' => $isVideo ? self::VIDEO_MAX_SIZE_KB : self::DOCUMENT_MAX_SIZE_KB,
                    'active' => true,
                    // Enum declaration order is the display order, which is
                    // already the order an applicant is asked for them in.
                    'sort_order' => $index + 1,
                ],
            );
        }
    }
}
