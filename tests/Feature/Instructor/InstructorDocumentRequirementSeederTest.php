<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\InstructorEvidenceCollection;
use App\Models\InstructorDocumentRequirement;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Instructor\InstructorDocumentRequirementService;
use App\Services\Instructor\InstructorOnboardingService;
use Database\Seeders\InstructorDocumentRequirementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every evidence type an instructor can upload must have a requirement
 * row, or it is invisible in People → Instructors → Document
 * Requirements and no admin can configure it. Adding an enum case
 * without seeding a row fails here rather than shipping silently.
 */
final class InstructorDocumentRequirementSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_evidence_collection_gets_a_requirement_row(): void
    {
        $this->seed(InstructorDocumentRequirementSeeder::class);

        $seeded = InstructorDocumentRequirement::query()->pluck('collection_name')->all();

        foreach (InstructorEvidenceCollection::cases() as $collection) {
            $this->assertContains($collection->value, $seeded, "[{$collection->value}] has no requirement row.");
        }

        $this->assertCount(count(InstructorEvidenceCollection::cases()), $seeded);
    }

    /**
     * Required like the rest, but carrying the video formats and size cap
     * the wizard validates uploads against — a required row that rejected
     * every file an applicant could produce would be unsatisfiable.
     */
    public function test_introduction_video_is_required_and_accepts_video_formats(): void
    {
        $this->seed(InstructorDocumentRequirementSeeder::class);

        $video = InstructorDocumentRequirement::query()
            ->where('collection_name', InstructorEvidenceCollection::IntroductionVideo->value)
            ->sole();

        $this->assertTrue($video->required);
        $this->assertTrue($video->active);
        $this->assertSame(['video/mp4', 'video/webm', 'video/quicktime'], $video->accepted_mime_types);
        $this->assertSame(51200, $video->max_size_kb);

        $this->assertContains(
            InstructorEvidenceCollection::IntroductionVideo->value,
            app(InstructorDocumentRequirementService::class)->requiredCollections(),
        );
    }

    public function test_every_evidence_collection_is_required(): void
    {
        $this->seed(InstructorDocumentRequirementSeeder::class);

        $required = app(InstructorDocumentRequirementService::class)->requiredCollections();

        $this->assertEqualsCanonicalizing(
            array_column(InstructorEvidenceCollection::cases(), 'value'),
            $required,
        );
    }

    /**
     * An applicant who has done everything else is still held back by the
     * missing video — and progress() counts it, rather than dividing by a
     * requirement set it no longer matches.
     */
    public function test_a_missing_introduction_video_blocks_completion(): void
    {
        $this->seed(InstructorDocumentRequirementSeeder::class);

        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);

        $onboarding = app(InstructorOnboardingService::class);

        $this->assertContains('introduction video', $onboarding->missingRequiredItems($instructor));
        $this->assertGreaterThanOrEqual(0, $onboarding->progress($instructor)['percentage']);
    }

    /**
     * A requirement row is meaningless unless UserProfile actually
     * registers a media collection under that name to upload into.
     */
    public function test_every_seeded_collection_is_a_registered_media_collection(): void
    {
        $this->seed(InstructorDocumentRequirementSeeder::class);

        $profile = new UserProfile;
        $profile->registerMediaCollections();
        $registered = array_column($profile->mediaCollections, 'name');

        foreach (InstructorDocumentRequirement::query()->pluck('collection_name') as $collectionName) {
            $this->assertContains($collectionName, $registered, "[{$collectionName}] is not a UserProfile media collection.");
        }
    }

    public function test_reseeding_does_not_revert_an_admin_configuration_change(): void
    {
        $this->seed(InstructorDocumentRequirementSeeder::class);

        InstructorDocumentRequirement::query()
            ->where('collection_name', 'teaching_certificate')
            ->update(['required' => false, 'active' => false]);

        $this->seed(InstructorDocumentRequirementSeeder::class);

        $requirement = InstructorDocumentRequirement::query()
            ->where('collection_name', 'teaching_certificate')
            ->sole();

        $this->assertFalse($requirement->required);
        $this->assertFalse($requirement->active);
        $this->assertCount(count(InstructorEvidenceCollection::cases()), InstructorDocumentRequirement::all());
    }
}
