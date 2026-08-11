<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Curriculum\Services\CurriculumService;
use App\Filament\Resources\Academic\Pages\CreateCurriculum;
use App\Filament\Resources\Academic\Pages\EditCurriculum;
use App\Filament\Resources\Academic\Pages\EditCurriculumVersion;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Subject;
use App\Models\SubjectTopic;
use App\Models\User;
use Database\Seeders\AcademicPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CurriculumResourceCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $unauthorized;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AcademicPermissionSeeder::class);

        $managerRole = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        $this->manager = User::factory()->create(['status' => 'active']);
        $this->manager->assignRole($managerRole);

        $this->unauthorized = User::factory()->create(['status' => 'active']);
    }

    private function subject(): Subject
    {
        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'school'], ['name' => 'School']);

        return Subject::create([
            'academic_category_id' => $category->id,
            'name' => 'Mathematics',
            'slug' => 'mathematics',
        ]);
    }

    private function level(): AcademicLevel
    {
        return AcademicLevel::create(['name' => 'High School', 'slug' => 'high-school']);
    }

    public function test_manager_can_list_curricula(): void
    {
        app(CurriculumService::class)->createCurriculum($this->manager, [
            'subject_id' => $this->subject()->id,
            'academic_level_id' => $this->level()->id,
            'name' => 'Algebra Foundations',
        ]);

        $this->actingAs($this->manager)
            ->get(route('filament.admin.resources.academic.curricula.index'))
            ->assertOk()
            ->assertSee('Algebra Foundations');
    }

    public function test_unauthorized_user_cannot_list_curricula(): void
    {
        $this->actingAs($this->unauthorized)
            ->get(route('filament.admin.resources.academic.curricula.index'))
            ->assertForbidden();
    }

    public function test_manager_can_create_curriculum(): void
    {
        $subject = $this->subject();
        $level = $this->level();

        $this->actingAs($this->manager);

        Livewire::test(CreateCurriculum::class)
            ->fillForm([
                'subject_id' => $subject->id,
                'academic_level_id' => $level->id,
                'name' => 'Algebra Foundations',
                'slug' => 'algebra-foundations',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('curricula', ['slug' => 'algebra-foundations']);
        $this->assertDatabaseHas('curriculum_versions', ['version_number' => 1, 'status' => 'draft']);
    }

    public function test_manager_can_edit_curriculum_identity_fields(): void
    {
        $curriculum = app(CurriculumService::class)->createCurriculum($this->manager, [
            'subject_id' => $this->subject()->id,
            'academic_level_id' => $this->level()->id,
            'name' => 'Algebra Foundations',
        ]);

        $this->actingAs($this->manager);

        Livewire::test(EditCurriculum::class, ['record' => $curriculum->getRouteKey()])
            ->fillForm(['name' => 'Algebra Foundations (Revised)'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Algebra Foundations (Revised)', $curriculum->fresh()->name);
    }

    public function test_publish_action_is_hidden_when_version_has_no_module(): void
    {
        $curriculum = app(CurriculumService::class)->createCurriculum($this->manager, [
            'subject_id' => $this->subject()->id,
            'academic_level_id' => $this->level()->id,
            'name' => 'Algebra Foundations',
        ]);
        $version = $curriculum->versions->first();

        $this->actingAs($this->manager);

        Livewire::test(EditCurriculumVersion::class, ['record' => $version->getRouteKey()])
            ->assertActionExists('publish')
            ->callAction('publish')
            ->assertNotified();

        // Publication is blocked by the service — status remains Draft.
        $this->assertSame('draft', $version->fresh()->status->value);
    }

    public function test_publish_action_succeeds_through_the_service_when_structurally_valid(): void
    {
        $subject = $this->subject();
        $curriculum = app(CurriculumService::class)->createCurriculum($this->manager, [
            'subject_id' => $subject->id,
            'academic_level_id' => $this->level()->id,
            'name' => 'Algebra Foundations',
        ]);
        $version = $curriculum->versions->first();
        $module = app(CurriculumService::class)->addModule($this->manager, $version, ['title' => 'Introduction']);
        $topic = SubjectTopic::factory()->create(['subject_id' => $subject->id]);
        app(CurriculumService::class)->assignTopic($this->manager, $module, $topic);

        $this->actingAs($this->manager);

        Livewire::test(EditCurriculumVersion::class, ['record' => $version->getRouteKey()])
            ->callAction('publish');

        $this->assertSame('published', $version->fresh()->status->value);
    }

    public function test_notes_field_is_disabled_on_a_published_version(): void
    {
        $subject = $this->subject();
        $curriculum = app(CurriculumService::class)->createCurriculum($this->manager, [
            'subject_id' => $subject->id,
            'academic_level_id' => $this->level()->id,
            'name' => 'Algebra Foundations',
        ]);
        $version = $curriculum->versions->first();
        $module = app(CurriculumService::class)->addModule($this->manager, $version, ['title' => 'Introduction']);
        app(CurriculumService::class)->assignTopic($this->manager, $module, SubjectTopic::factory()->create(['subject_id' => $subject->id]));
        $version = app(CurriculumService::class)->publish($this->manager, $version);

        $this->actingAs($this->manager);

        // Attempting to change notes on a Published version must not
        // mutate it — the service rejects any structural/content write
        // once a version has left Draft.
        Livewire::test(EditCurriculumVersion::class, ['record' => $version->getRouteKey()])
            ->fillForm(['notes' => 'Attempted edit'])
            ->call('save');

        $this->assertNull($version->fresh()->notes);
    }
}
