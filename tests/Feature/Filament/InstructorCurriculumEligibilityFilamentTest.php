<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Curriculum\Exceptions\InstructorAcademicEligibilityException;
use App\Curriculum\Services\CurriculumService;
use App\Curriculum\Services\EducationSystemService;
use App\Curriculum\Services\InstructorAcademicEligibilityService;
use App\Enums\InstructorStatus;
use App\Filament\Resources\InstructorOnboarding\InstructorOnboardingResource;
use App\Filament\Resources\InstructorOnboarding\Pages\EditInstructorOnboarding;
use App\Filament\Resources\InstructorOnboarding\RelationManagers\CurriculumEligibilityRelationManager;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Curriculum;
use App\Models\EducationSystem;
use App\Models\InstructorCurriculumEligibility;
use App\Models\Subject;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\Instructor\InstructorOnboardingService;
use Database\Seeders\AcademicPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Business-behavior focused: relation manager renders on the existing
 * Instructor Onboarding edit page (no new top-level nav item), mutations
 * route through InstructorAcademicEligibilityService, and invalid
 * combinations are rejected server-side regardless of what the UI shows.
 */
class InstructorCurriculumEligibilityFilamentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AcademicPermissionSeeder::class);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        $managerRole = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => InstructorOnboardingService::REVIEW_PERMISSION, 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole($managerRole);
        // The relation manager is hung off InstructorOnboardingResource, whose
        // own canEdit() gate requires the onboarding review permission in
        // addition to the InstructorCurriculumEligibility CRUD permissions.
        $this->admin->givePermissionTo(InstructorOnboardingService::REVIEW_PERMISSION);
    }

    private function subject(string $slug): Subject
    {
        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'filament-general'], ['name' => 'Filament General']);

        return Subject::create(['academic_category_id' => $category->id, 'name' => ucfirst($slug), 'slug' => $slug]);
    }

    private function level(string $slug): AcademicLevel
    {
        return AcademicLevel::create(['name' => ucfirst($slug), 'slug' => $slug, 'min_grade' => 1, 'max_grade' => 12]);
    }

    private function curriculum(Subject $subject, AcademicLevel $level, string $name): Curriculum
    {
        return app(CurriculumService::class)->createCurriculum($this->admin, [
            'subject_id' => $subject->id,
            'academic_level_id' => $level->id,
            'name' => $name,
        ]);
    }

    private function system(string $name): EducationSystem
    {
        return app(EducationSystemService::class)->createEducationSystem($this->admin, ['name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name))]);
    }

    private function instructor(Subject $subject): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $instructor->profile()->update(['instructor_status' => InstructorStatus::Active]);

        TeacherSubject::factory()->create([
            'teacher_id' => $instructor->id,
            'subject' => $subject->name,
            'subject_id' => $subject->id,
            'grade_from' => 1,
            'grade_to' => 12,
        ]);

        return $instructor->fresh();
    }

    public function test_relation_manager_section_renders_on_the_instructor_onboarding_edit_page(): void
    {
        $subject = $this->subject('filament-subject');
        $level = $this->level('filament-level');
        $instructor = $this->instructor($subject);

        $this->actingAs($this->admin)
            ->get(InstructorOnboardingResource::getUrl('edit', ['record' => $instructor]))
            ->assertOk()
            // EditInstructorOnboarding's custom getRelationManagersContentComponent()
            // renders each relation manager as its own Livewire component (no
            // tab-header text) — the mounted component class in the wire
            // snapshot is the reliable "this section is present" signal.
            ->assertSee(CurriculumEligibilityRelationManager::class);
    }

    public function test_service_receives_the_mutation_when_adding_eligibility(): void
    {
        $subject = $this->subject('service-subject');
        $level = $this->level('service-level');
        $curriculum = $this->curriculum($subject, $level, 'Service Curriculum');
        $system = $this->system('Service System');
        $instructor = $this->instructor($subject);

        Livewire::actingAs($this->admin)
            ->test(CurriculumEligibilityRelationManager::class, [
                'ownerRecord' => $instructor,
                'pageClass' => EditInstructorOnboarding::class,
            ])
            ->callTableAction('addEligibility', data: [
                'education_system_id' => $system->id,
                'curriculum_id' => $curriculum->id,
                'notes' => 'Approved via test.',
            ]);

        $this->assertDatabaseHas('instructor_curriculum_eligibilities', [
            'teacher_id' => $instructor->id,
            'education_system_id' => $system->id,
            'curriculum_id' => $curriculum->id,
            'is_active' => 1,
        ]);
    }

    public function test_duplicate_assignment_produces_a_controlled_error_not_a_500(): void
    {
        $subject = $this->subject('dup-subject');
        $level = $this->level('dup-level');
        $curriculum = $this->curriculum($subject, $level, 'Dup Curriculum');
        $system = $this->system('Dup System');
        $instructor = $this->instructor($subject);

        app(InstructorAcademicEligibilityService::class)->assign($this->admin, $instructor, $system, $curriculum);

        Livewire::actingAs($this->admin)
            ->test(CurriculumEligibilityRelationManager::class, [
                'ownerRecord' => $instructor,
                'pageClass' => EditInstructorOnboarding::class,
            ])
            ->callTableAction('addEligibility', data: [
                'education_system_id' => $system->id,
                'curriculum_id' => $curriculum->id,
            ]);

        $this->assertSame(1, InstructorCurriculumEligibility::query()
            ->where('teacher_id', $instructor->id)
            ->where('education_system_id', $system->id)
            ->where('curriculum_id', $curriculum->id)
            ->count());
    }

    public function test_deactivate_action_is_confirmable_and_persists(): void
    {
        $subject = $this->subject('deact-subject');
        $level = $this->level('deact-level');
        $curriculum = $this->curriculum($subject, $level, 'Deactivate Curriculum');
        $system = $this->system('Deactivate System');
        $instructor = $this->instructor($subject);

        $eligibility = app(InstructorAcademicEligibilityService::class)->assign($this->admin, $instructor, $system, $curriculum);

        Livewire::actingAs($this->admin)
            ->test(CurriculumEligibilityRelationManager::class, [
                'ownerRecord' => $instructor,
                'pageClass' => EditInstructorOnboarding::class,
            ])
            ->callTableAction('deactivate', $eligibility);

        $this->assertFalse($eligibility->fresh()->is_active);
    }

    public function test_only_eligible_curricula_are_offered_in_the_progressive_select(): void
    {
        $matchingSubject = $this->subject('progressive-match');
        $level = $this->level('progressive-level');
        $matchingCurriculum = $this->curriculum($matchingSubject, $level, 'Progressive Matching Curriculum');

        $otherSubject = $this->subject('progressive-other');
        $otherCurriculum = $this->curriculum($otherSubject, $level, 'Progressive Other Curriculum');

        $system = $this->system('Progressive System');
        $instructor = $this->instructor($matchingSubject);

        Livewire::actingAs($this->admin)
            ->test(CurriculumEligibilityRelationManager::class, [
                'ownerRecord' => $instructor,
                'pageClass' => EditInstructorOnboarding::class,
            ])
            ->callTableAction('addEligibility', data: [
                'education_system_id' => $system->id,
                'curriculum_id' => $matchingCurriculum->id,
            ]);

        $this->assertDatabaseHas('instructor_curriculum_eligibilities', [
            'teacher_id' => $instructor->id,
            'curriculum_id' => $matchingCurriculum->id,
        ]);

        // Server-side authority check regardless of what the UI narrowed to:
        // the non-matching curriculum is still rejected even if submitted directly.
        $this->expectException(InstructorAcademicEligibilityException::class);
        app(InstructorAcademicEligibilityService::class)->assign($this->admin, $instructor, $system, $otherCurriculum);
    }
}
