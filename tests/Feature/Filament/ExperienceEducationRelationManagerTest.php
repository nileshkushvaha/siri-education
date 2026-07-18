<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\EducationLevel;
use App\Enums\EmploymentType;
use App\Filament\Resources\InstructorOnboarding\Pages\EditInstructorOnboarding;
use App\Filament\Resources\Users\RelationManagers\EducationsRelationManager;
use App\Filament\Resources\Users\RelationManagers\ExperiencesRelationManager;
use App\Models\User;
use App\Models\UserEducation;
use App\Models\UserExperience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Integration tests for the two Relation Managers via Livewire. Mirrors
 * UserResourceProfileTest's approach — test at the Livewire component level
 * rather than HTTP, since Relation Managers live inside Filament's Livewire
 * rendering context.
 */
class ExperienceEducationRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $subject;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $this->superAdmin->assignRole('super_admin');
        $this->actingAs($this->superAdmin);

        $this->subject = User::factory()->create();
    }

    // ── ExperiencesRelationManager ────────────────────────────────────────

    public function test_experiences_relation_manager_mounts_successfully(): void
    {
        Livewire::test(ExperiencesRelationManager::class, [
            'ownerRecord' => $this->subject,
            'pageClass' => EditInstructorOnboarding::class,
        ])->assertSuccessful();
    }

    public function test_creating_experience_via_relation_manager_persists_record(): void
    {
        Livewire::test(ExperiencesRelationManager::class, [
            'ownerRecord' => $this->subject,
            'pageClass' => EditInstructorOnboarding::class,
        ])
            ->callTableAction('create', data: [
                'organization_name' => 'Tech Giants',
                'designation' => 'Staff Engineer',
                'employment_type' => EmploymentType::FullTime->value,
                'is_current' => true,
                'start_date' => now()->subYears(2)->toDateString(),
                'end_date' => null,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('user_experiences', [
            'user_id' => $this->subject->id,
            'organization_name' => 'Tech Giants',
            'designation' => 'Staff Engineer',
        ]);
    }

    public function test_editing_experience_via_relation_manager_updates_record(): void
    {
        $experience = UserExperience::factory()->for($this->subject)->create([
            'designation' => 'Old Title',
        ]);

        Livewire::test(ExperiencesRelationManager::class, [
            'ownerRecord' => $this->subject,
            'pageClass' => EditInstructorOnboarding::class,
        ])
            ->callTableAction('edit', record: $experience, data: [
                'designation' => 'New Title',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('New Title', $experience->fresh()->designation);
    }

    public function test_deleting_experience_via_relation_manager_soft_deletes_record(): void
    {
        $experience = UserExperience::factory()->for($this->subject)->create();

        Livewire::test(ExperiencesRelationManager::class, [
            'ownerRecord' => $this->subject,
            'pageClass' => EditInstructorOnboarding::class,
        ])
            ->callTableAction('delete', record: $experience)
            ->assertHasNoTableActionErrors();

        $this->assertSoftDeleted('user_experiences', ['id' => $experience->id]);
    }

    public function test_experience_table_shows_existing_records(): void
    {
        UserExperience::factory()->for($this->subject)->create([
            'organization_name' => 'Visible Corp',
        ]);

        Livewire::test(ExperiencesRelationManager::class, [
            'ownerRecord' => $this->subject,
            'pageClass' => EditInstructorOnboarding::class,
        ])->assertSee('Visible Corp');
    }

    // ── EducationsRelationManager ─────────────────────────────────────────

    public function test_educations_relation_manager_mounts_successfully(): void
    {
        Livewire::test(EducationsRelationManager::class, [
            'ownerRecord' => $this->subject,
            'pageClass' => EditInstructorOnboarding::class,
        ])->assertSuccessful();
    }

    public function test_creating_education_via_relation_manager_persists_record(): void
    {
        Livewire::test(EducationsRelationManager::class, [
            'ownerRecord' => $this->subject,
            'pageClass' => EditInstructorOnboarding::class,
        ])
            ->callTableAction('create', data: [
                'institution_name' => 'State University',
                'degree' => 'Bachelor of Science',
                'education_level' => EducationLevel::Bachelor->value,
                'is_current' => false,
                'start_date' => now()->subYears(4)->toDateString(),
                'end_date' => now()->subYear()->toDateString(),
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('user_educations', [
            'user_id' => $this->subject->id,
            'institution_name' => 'State University',
        ]);
    }

    public function test_deleting_education_via_relation_manager_soft_deletes_record(): void
    {
        $education = UserEducation::factory()->for($this->subject)->create();

        Livewire::test(EducationsRelationManager::class, [
            'ownerRecord' => $this->subject,
            'pageClass' => EditInstructorOnboarding::class,
        ])
            ->callTableAction('delete', record: $education)
            ->assertHasNoTableActionErrors();

        $this->assertSoftDeleted('user_educations', ['id' => $education->id]);
    }

    public function test_education_table_shows_existing_records(): void
    {
        UserEducation::factory()->for($this->subject)->create([
            'institution_name' => 'Visible University',
        ]);

        Livewire::test(EducationsRelationManager::class, [
            'ownerRecord' => $this->subject,
            'pageClass' => EditInstructorOnboarding::class,
        ])->assertSee('Visible University');
    }

    // ── Observer integration: Relation Manager actions trigger Activity Log ─

    public function test_creating_via_relation_manager_logs_experience_added(): void
    {
        Livewire::test(ExperiencesRelationManager::class, [
            'ownerRecord' => $this->subject,
            'pageClass' => EditInstructorOnboarding::class,
        ])
            ->callTableAction('create', data: [
                'organization_name' => 'LogTest Co',
                'designation' => 'Tester',
                'employment_type' => EmploymentType::Contract->value,
                'is_current' => true,
                'start_date' => now()->subYear()->toDateString(),
                'end_date' => null,
            ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'experience',
            'event' => 'experience_added',
        ]);
    }
}
