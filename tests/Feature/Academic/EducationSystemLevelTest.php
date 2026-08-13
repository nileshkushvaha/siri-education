<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Curriculum\Exceptions\AcademicContextException;
use App\Curriculum\Services\EducationSystemService;
use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Models\AcademicLevel;
use App\Models\EducationSystem;
use App\Models\EducationSystemLevel;
use App\Models\User;
use Database\Seeders\AcademicPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3.1 §35 — EducationSystemLevel: the exact, student-selectable
 * level within an Education System (CBSE "Class 10", US "Grade 10", UK
 * "Year 10"), distinct from the broad AcademicLevel band it belongs to.
 */
class EducationSystemLevelTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AcademicPermissionSeeder::class);

        $role = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $this->manager = User::factory()->create(['status' => 'active']);
        $this->manager->assignRole($role);
    }

    private function service(): EducationSystemService
    {
        return app(EducationSystemService::class);
    }

    private function system(string $slug = 'cbse'): EducationSystem
    {
        return $this->service()->createEducationSystem($this->manager, ['name' => strtoupper($slug), 'slug' => $slug]);
    }

    private function academicLevel(string $slug = 'middle-school'): AcademicLevel
    {
        return AcademicLevel::create(['name' => ucfirst(str_replace('-', ' ', $slug)), 'slug' => $slug]);
    }

    // ── Creation ──────────────────────────────────────────────────────────

    public function test_manager_can_add_a_level_to_an_education_system(): void
    {
        $system = $this->system();
        $level = $this->academicLevel();

        $educationSystemLevel = $this->service()->addLevel($this->manager, $system, [
            'academic_level_id' => $level->id,
            'value' => '10',
            'display_label' => 'Class 10',
            'normalized_grade' => 10,
        ]);

        $this->assertDatabaseHas('education_system_levels', [
            'id' => $educationSystemLevel->id,
            'education_system_id' => $system->id,
            'academic_level_id' => $level->id,
            'value' => '10',
            'display_label' => 'Class 10',
            'normalized_grade' => 10,
            'is_active' => 1,
        ]);
    }

    public function test_normalized_grade_is_nullable_for_non_numeric_levels(): void
    {
        $system = $this->system();
        $level = $this->academicLevel('undergraduate');

        $educationSystemLevel = $this->service()->addLevel($this->manager, $system, [
            'academic_level_id' => $level->id,
            'value' => 'ug',
            'display_label' => 'Undergraduate',
        ]);

        $this->assertNull($educationSystemLevel->normalized_grade);
    }

    // ── Uniqueness ────────────────────────────────────────────────────────

    public function test_duplicate_value_within_the_same_system_is_rejected(): void
    {
        $system = $this->system();
        $level = $this->academicLevel();

        $this->service()->addLevel($this->manager, $system, ['academic_level_id' => $level->id, 'value' => '10', 'display_label' => 'Class 10', 'normalized_grade' => 10]);

        $this->expectException(AcademicContextException::class);
        $this->service()->addLevel($this->manager, $system, ['academic_level_id' => $level->id, 'value' => '10', 'display_label' => 'Class 10 Again', 'normalized_grade' => 10]);
    }

    public function test_duplicate_value_within_the_same_system_is_rejected_at_database_level(): void
    {
        $system = $this->system();
        $level = $this->academicLevel();

        $this->service()->addLevel($this->manager, $system, ['academic_level_id' => $level->id, 'value' => '10', 'display_label' => 'Class 10', 'normalized_grade' => 10]);

        $this->expectException(QueryException::class);
        EducationSystemLevel::query()->create([
            'education_system_id' => $system->id,
            'academic_level_id' => $level->id,
            'value' => '10',
            'display_label' => 'Class 10 Direct',
        ]);
    }

    public function test_same_value_may_be_reused_across_different_systems(): void
    {
        $systemA = $this->system('cbse-uniq-a');
        $systemB = $this->system('cbse-uniq-b');
        $level = $this->academicLevel();

        $a = $this->service()->addLevel($this->manager, $systemA, ['academic_level_id' => $level->id, 'value' => '10', 'display_label' => 'Class 10', 'normalized_grade' => 10]);
        $b = $this->service()->addLevel($this->manager, $systemB, ['academic_level_id' => $level->id, 'value' => '10', 'display_label' => 'Grade 10', 'normalized_grade' => 10]);

        $this->assertNotSame($a->id, $b->id);
    }

    public function test_multiple_levels_may_share_the_same_broad_academic_level_band(): void
    {
        $system = $this->system();
        $middleSchool = $this->academicLevel('middle-school-shared');

        $this->service()->addLevel($this->manager, $system, ['academic_level_id' => $middleSchool->id, 'value' => '6', 'display_label' => 'Class 6', 'normalized_grade' => 6]);
        $this->service()->addLevel($this->manager, $system, ['academic_level_id' => $middleSchool->id, 'value' => '7', 'display_label' => 'Class 7', 'normalized_grade' => 7]);
        $this->service()->addLevel($this->manager, $system, ['academic_level_id' => $middleSchool->id, 'value' => '8', 'display_label' => 'Class 8', 'normalized_grade' => 8]);

        $this->assertSame(3, EducationSystemLevel::query()->where('academic_level_id', $middleSchool->id)->count());
    }

    // ── Ordering ──────────────────────────────────────────────────────────

    public function test_levels_are_ordered_by_display_order(): void
    {
        $system = $this->system();
        $level = $this->academicLevel();

        $this->service()->addLevel($this->manager, $system, ['academic_level_id' => $level->id, 'value' => '8', 'display_label' => 'Class 8', 'normalized_grade' => 8, 'display_order' => 2]);
        $this->service()->addLevel($this->manager, $system, ['academic_level_id' => $level->id, 'value' => '6', 'display_label' => 'Class 6', 'normalized_grade' => 6, 'display_order' => 0]);
        $this->service()->addLevel($this->manager, $system, ['academic_level_id' => $level->id, 'value' => '7', 'display_label' => 'Class 7', 'normalized_grade' => 7, 'display_order' => 1]);

        $ordered = $system->levels()->orderBy('display_order')->pluck('value')->all();

        $this->assertSame(['6', '7', '8'], $ordered);
    }

    // ── Active/inactive ───────────────────────────────────────────────────

    public function test_inactive_levels_are_excluded_from_the_active_scope(): void
    {
        $system = $this->system();
        $level = $this->academicLevel();

        $this->service()->addLevel($this->manager, $system, ['academic_level_id' => $level->id, 'value' => '10', 'display_label' => 'Class 10', 'normalized_grade' => 10, 'is_active' => false]);

        $this->assertSame(0, EducationSystemLevel::query()->active()->count());
    }

    // ── Relationships ─────────────────────────────────────────────────────

    public function test_level_belongs_to_its_education_system_and_academic_level(): void
    {
        $system = $this->system();
        $academicLevel = $this->academicLevel();
        $level = $this->service()->addLevel($this->manager, $system, ['academic_level_id' => $academicLevel->id, 'value' => '10', 'display_label' => 'Class 10', 'normalized_grade' => 10]);

        $this->assertTrue($level->educationSystem->is($system));
        $this->assertTrue($level->academicLevel->is($academicLevel));
    }

    // ── Update / remove ───────────────────────────────────────────────────

    public function test_manager_can_update_a_level(): void
    {
        $system = $this->system();
        $level = $this->service()->addLevel($this->manager, $system, ['academic_level_id' => $this->academicLevel()->id, 'value' => '10', 'display_label' => 'Class 10', 'normalized_grade' => 10]);

        $updated = $this->service()->updateLevel($this->manager, $level, ['display_label' => 'Standard 10']);

        $this->assertSame('Standard 10', $updated->display_label);
    }

    public function test_manager_can_remove_a_level(): void
    {
        $system = $this->system();
        $level = $this->service()->addLevel($this->manager, $system, ['academic_level_id' => $this->academicLevel()->id, 'value' => '10', 'display_label' => 'Class 10', 'normalized_grade' => 10]);

        $this->service()->removeLevel($this->manager, $level);

        $this->assertSoftDeleted('education_system_levels', ['id' => $level->id]);
    }

    /** Historical safety: a snapshotted level's id must survive a blocked force-delete attempt. */
    public function test_level_cannot_be_force_deleted(): void
    {
        $system = $this->system();
        $level = $this->service()->addLevel($this->manager, $system, ['academic_level_id' => $this->academicLevel()->id, 'value' => '10', 'display_label' => 'Class 10', 'normalized_grade' => 10]);

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);
        $level->forceDelete();
    }

    // ── Authorization ─────────────────────────────────────────────────────

    public function test_unauthorized_user_cannot_add_a_level(): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $system = $this->system();

        $this->expectException(AuthorizationException::class);
        $this->service()->addLevel($student, $system, ['academic_level_id' => $this->academicLevel()->id, 'value' => '10', 'display_label' => 'Class 10']);
    }

    public function test_value_and_display_label_are_required(): void
    {
        $system = $this->system();

        $this->expectException(ValidationException::class);
        $this->service()->addLevel($this->manager, $system, ['academic_level_id' => $this->academicLevel()->id, 'value' => '', 'display_label' => '']);
    }
}
