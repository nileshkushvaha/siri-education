<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\InstructorStatus;
use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Models\Activity;
use App\Models\InstructorDocumentRequirement;
use App\Models\User;
use App\Services\Instructor\InstructorDocumentRequirementService;
use App\Services\Instructor\InstructorOnboardingService;
use Database\Seeders\InstructorDocumentRequirementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InstructorDocumentRequirementTest extends TestCase
{
    use RefreshDatabase;

    private InstructorDocumentRequirementService $requirements;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        foreach (['ViewAny', 'View', 'Create', 'Update'] as $action) {
            Permission::firstOrCreate(['name' => "{$action}:InstructorDocumentRequirement", 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('manager');
        $this->admin->givePermissionTo(['ViewAny:InstructorDocumentRequirement', 'View:InstructorDocumentRequirement', 'Create:InstructorDocumentRequirement', 'Update:InstructorDocumentRequirement']);

        $this->requirements = app(InstructorDocumentRequirementService::class);
    }

    public function test_active_requirements_are_returned(): void
    {
        $this->requirements->create($this->admin, $this->requirementData('resume', required: true));
        $inactive = $this->requirements->create($this->admin, $this->requirementData('optional_doc', required: false));
        $this->requirements->update($this->admin, $inactive, ['active' => false]);

        $active = $this->requirements->activeRequirements();

        $this->assertCount(1, $active);
        $this->assertSame('resume', $active->first()->collection_name);
    }

    public function test_inactive_requirement_is_hidden_from_required_and_active_collections(): void
    {
        $requirement = $this->requirements->create($this->admin, $this->requirementData('government_id', required: true));
        $this->requirements->update($this->admin, $requirement, ['active' => false]);

        $this->assertNotContains('government_id', $this->requirements->requiredCollections());
        $this->assertNotContains('government_id', $this->requirements->activeCollectionNames());
    }

    public function test_required_validation_reflects_current_active_requirements(): void
    {
        $this->seed(InstructorDocumentRequirementSeeder::class);
        $this->requirements->create($this->admin, $this->requirementData('extra_certificate', required: true));

        $onboarding = app(InstructorOnboardingService::class);
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);

        $missing = $onboarding->missingRequiredItems($instructor);

        $this->assertContains('extra certificate', $missing);
    }

    public function test_deactivating_a_requirement_does_not_retroactively_invalidate_a_submitted_application(): void
    {
        $this->seed(InstructorDocumentRequirementSeeder::class);
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);
        $instructor->assignRole('instructor');
        $instructor->profile()->update(['instructor_status' => InstructorStatus::Submitted]);

        $governmentId = InstructorDocumentRequirement::query()->where('collection_name', 'government_id')->firstOrFail();
        $this->requirements->update($this->admin, $governmentId, ['active' => false]);

        // The already-submitted application's lifecycle status is completely
        // untouched by a later requirement change — nothing re-validates a
        // submitted/under-review/approved/active profile against the
        // current requirement set.
        $this->assertSame(InstructorStatus::Submitted, $instructor->fresh()->profile->instructor_status);

        // The now-inactive requirement no longer shows up as "missing" for
        // ANY applicant going forward, including this one, if it were ever
        // re-evaluated — proving the change is forward-only.
        $this->assertNotContains('government id', app(InstructorOnboardingService::class)->missingRequiredItems($instructor->fresh()));
    }

    public function test_requirement_can_never_be_deleted(): void
    {
        $requirement = $this->requirements->create($this->admin, $this->requirementData('resume', required: true));

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);

        $requirement->delete();
    }

    public function test_creating_a_requirement_is_audited(): void
    {
        $requirement = $this->requirements->create($this->admin, $this->requirementData('resume', required: true));

        $this->assertSame(
            1,
            Activity::query()
                ->where('log_name', 'instructor')
                ->where('event', 'instructor_document_requirement_created')
                ->where('subject_id', $requirement->id)
                ->count(),
        );
    }

    public function test_deactivating_a_requirement_logs_the_disabled_event_not_the_generic_updated_event(): void
    {
        $requirement = $this->requirements->create($this->admin, $this->requirementData('resume', required: true));

        $this->requirements->update($this->admin, $requirement, ['active' => false]);

        $this->assertSame(
            1,
            Activity::query()->where('log_name', 'instructor')->where('event', 'instructor_document_requirement_disabled')->count(),
        );
        $this->assertSame(
            0,
            Activity::query()->where('log_name', 'instructor')->where('event', 'instructor_document_requirement_updated')->count(),
        );
    }

    public function test_audit_metadata_never_contains_forbidden_fields(): void
    {
        $requirement = $this->requirements->create($this->admin, $this->requirementData('resume', required: true));

        $activity = Activity::query()
            ->where('log_name', 'instructor')
            ->where('event', 'instructor_document_requirement_created')
            ->where('subject_id', $requirement->id)
            ->firstOrFail();

        $properties = $activity->properties->toArray();
        $this->assertArrayNotHasKey('document_content', $properties);
        $this->assertArrayNotHasKey('file_path', $properties);
        $this->assertArrayNotHasKey('personal_data', $properties);
    }

    private function requirementData(string $collection, bool $required): array
    {
        return [
            'collection_name' => $collection,
            'label' => str($collection)->replace('_', ' ')->title()->toString(),
            'required' => $required,
            'accepted_mime_types' => ['application/pdf'],
            'max_size_kb' => 4096,
            'active' => true,
            'sort_order' => 0,
        ];
    }
}
