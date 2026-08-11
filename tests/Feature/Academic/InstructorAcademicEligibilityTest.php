<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Curriculum\Exceptions\InstructorAcademicEligibilityException;
use App\Curriculum\Services\CurriculumService;
use App\Curriculum\Services\EducationSystemService;
use App\Curriculum\Services\InstructorAcademicEligibilityService;
use App\Enums\AcademicStatus;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Curriculum;
use App\Models\EducationSystem;
use App\Models\InstructorCurriculumEligibility;
use App\Models\Subject;
use App\Models\TeacherSubject;
use App\Models\User;
use Database\Seeders\AcademicPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorAcademicEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AcademicPermissionSeeder::class);

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        $managerRole = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole($managerRole);
    }

    private function service(): InstructorAcademicEligibilityService
    {
        return app(InstructorAcademicEligibilityService::class);
    }

    private function educationSystemService(): EducationSystemService
    {
        return app(EducationSystemService::class);
    }

    private function curriculumService(): CurriculumService
    {
        return app(CurriculumService::class);
    }

    private function subject(string $slug, bool $active = true): Subject
    {
        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'general-eligibility'], ['name' => 'General Eligibility']);

        return Subject::create([
            'academic_category_id' => $category->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => $active ? AcademicStatus::Active : AcademicStatus::Inactive,
        ]);
    }

    private function level(string $slug, ?int $min = 1, ?int $max = 12, bool $active = true): AcademicLevel
    {
        return AcademicLevel::create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'min_grade' => $min,
            'max_grade' => $max,
            'status' => $active ? AcademicStatus::Active : AcademicStatus::Inactive,
        ]);
    }

    private function curriculum(Subject $subject, AcademicLevel $level, string $name): Curriculum
    {
        return $this->curriculumService()->createCurriculum($this->admin, [
            'subject_id' => $subject->id,
            'academic_level_id' => $level->id,
            'name' => $name,
        ]);
    }

    private function system(string $name, bool $active = true): EducationSystem
    {
        $system = $this->educationSystemService()->createEducationSystem($this->admin, ['name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name))]);

        if (! $active) {
            $system->update(['status' => AcademicStatus::Inactive]);
        }

        return $system->fresh();
    }

    private function instructor(): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');

        return $instructor;
    }

    private function teaches(User $instructor, Subject $subject, ?int $from = 1, ?int $to = 12): TeacherSubject
    {
        return TeacherSubject::factory()->create([
            'teacher_id' => $instructor->id,
            'subject' => $subject->name,
            'subject_id' => $subject->id,
            'grade_from' => $from,
            'grade_to' => $to,
        ]);
    }

    // ── Valid assignment ────────────────────────────────────────────────

    public function test_valid_assignment_succeeds_when_instructor_already_teaches_subject_and_level(): void
    {
        $subject = $this->subject('algebra-v');
        $level = $this->level('middle-v');
        $curriculum = $this->curriculum($subject, $level, 'Algebra V Curriculum');
        $system = $this->system('CBSE V');
        $this->educationSystemService()->mapToCurriculum($this->admin, $system, $curriculum);

        $instructor = $this->instructor();
        $this->teaches($instructor, $subject, 1, 12);

        $eligibility = $this->service()->assign($this->admin, $instructor, $system, $curriculum);

        $this->assertTrue($eligibility->is_active);
        $this->assertNotNull($eligibility->approved_at);
        $this->assertSame($this->admin->id, $eligibility->approved_by);
        $this->assertDatabaseHas('instructor_curriculum_eligibilities', [
            'teacher_id' => $instructor->id,
            'education_system_id' => $system->id,
            'curriculum_id' => $curriculum->id,
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'instructor_academic_eligibility',
            'event' => 'instructor_curriculum_eligibility_added',
        ]);
    }

    // ── Wrong Subject / Level / Education System ────────────────────────

    public function test_wrong_subject_is_rejected(): void
    {
        $taughtSubject = $this->subject('physics-v');
        $curriculumSubject = $this->subject('chemistry-v');
        $level = $this->level('secondary-v');
        $curriculum = $this->curriculum($curriculumSubject, $level, 'Chemistry V Curriculum');
        $system = $this->system('IB V');

        $instructor = $this->instructor();
        $this->teaches($instructor, $taughtSubject);

        $this->expectException(InstructorAcademicEligibilityException::class);
        $this->service()->assign($this->admin, $instructor, $system, $curriculum);
    }

    public function test_wrong_grade_range_is_rejected(): void
    {
        $subject = $this->subject('biology-v');
        $level = $this->level('senior-v', 9, 12);
        $curriculum = $this->curriculum($subject, $level, 'Biology V Curriculum');
        $system = $this->system('AP V');

        $instructor = $this->instructor();
        // Only teaches grades 1-5, level needs 9-12 covered.
        $this->teaches($instructor, $subject, 1, 5);

        $this->expectException(InstructorAcademicEligibilityException::class);
        $this->service()->assign($this->admin, $instructor, $system, $curriculum);
    }

    public function test_curriculum_not_mapped_to_selected_education_system_is_rejected(): void
    {
        $subject = $this->subject('history-v');
        $level = $this->level('senior-history-v');
        $curriculum = $this->curriculum($subject, $level, 'History V Curriculum');
        $mappedSystem = $this->system('GCSE V');
        $otherSystem = $this->system('SAT V');
        $this->educationSystemService()->mapToCurriculum($this->admin, $mappedSystem, $curriculum);

        $instructor = $this->instructor();
        $this->teaches($instructor, $subject);

        $this->expectException(InstructorAcademicEligibilityException::class);
        $this->service()->assign($this->admin, $instructor, $otherSystem, $curriculum);
    }

    // ── Global / system-neutral curriculum ──────────────────────────────

    public function test_global_curriculum_can_be_approved_against_any_active_system(): void
    {
        $subject = $this->subject('geography-v');
        $level = $this->level('geo-level-v');
        $curriculum = $this->curriculum($subject, $level, 'Global Geography Curriculum');
        // Deliberately no mapToCurriculum() call — zero mappings = globally applicable.
        $system = $this->system('Global System V');

        $instructor = $this->instructor();
        $this->teaches($instructor, $subject);

        $eligibility = $this->service()->assign($this->admin, $instructor, $system, $curriculum);

        $this->assertTrue($eligibility->is_active);
    }

    // ── Inactive System/Subject/AcademicLevel ───────────────────────────

    public function test_inactive_education_system_is_rejected(): void
    {
        $subject = $this->subject('economics-v');
        $level = $this->level('econ-level-v');
        $curriculum = $this->curriculum($subject, $level, 'Economics V Curriculum');
        $system = $this->system('Inactive System V', active: false);

        $instructor = $this->instructor();
        $this->teaches($instructor, $subject);

        $this->expectException(InstructorAcademicEligibilityException::class);
        $this->service()->assign($this->admin, $instructor, $system, $curriculum);
    }

    public function test_inactive_subject_is_rejected(): void
    {
        // CurriculumService::createCurriculum() itself requires an active
        // Subject at creation time, so the Subject is deactivated
        // afterward to isolate this rule from Curriculum creation rules.
        $subject = $this->subject('civics-v');
        $level = $this->level('civics-level-v');
        $curriculum = $this->curriculum($subject, $level, 'Civics V Curriculum');
        $system = $this->system('Civics System V');

        $instructor = $this->instructor();
        $this->teaches($instructor, $subject);
        $subject->update(['status' => AcademicStatus::Inactive]);

        $this->expectException(InstructorAcademicEligibilityException::class);
        $this->service()->assign($this->admin, $instructor, $system, $curriculum);
    }

    public function test_inactive_academic_level_is_rejected(): void
    {
        $subject = $this->subject('art-v');
        $level = $this->level('art-level-v');
        $curriculum = $this->curriculum($subject, $level, 'Art V Curriculum');
        $system = $this->system('Art System V');
        $level->update(['status' => AcademicStatus::Inactive]);

        $instructor = $this->instructor();
        $this->teaches($instructor, $subject);

        $this->expectException(InstructorAcademicEligibilityException::class);
        $this->service()->assign($this->admin, $instructor, $system, $curriculum);
    }

    // ── Administrative eligibility vs. runtime bookability ──────────────

    public function test_administrative_eligibility_can_be_created_without_a_published_curriculum_version(): void
    {
        $subject = $this->subject('music-v');
        $level = $this->level('music-level-v');
        // Deliberately never published — createCurriculum() alone leaves the version Draft.
        $curriculum = $this->curriculum($subject, $level, 'Unpublished Music V Curriculum');
        $system = $this->system('Music System V');

        $instructor = $this->instructor();
        $this->teaches($instructor, $subject);

        $eligibility = $this->service()->assign($this->admin, $instructor, $system, $curriculum);

        $this->assertTrue($eligibility->is_active);
        $this->assertNull($curriculum->latestPublishedVersion());
    }

    // ── Deactivate / Reactivate ──────────────────────────────────────────

    public function test_deactivate_and_reactivate_round_trip(): void
    {
        $subject = $this->subject('sanskrit-v');
        $level = $this->level('sanskrit-level-v');
        $curriculum = $this->curriculum($subject, $level, 'Sanskrit V Curriculum');
        $system = $this->system('Sanskrit System V');

        $instructor = $this->instructor();
        $this->teaches($instructor, $subject);

        $eligibility = $this->service()->assign($this->admin, $instructor, $system, $curriculum);

        $deactivated = $this->service()->deactivate($this->admin, $eligibility, 'no longer active');
        $this->assertFalse($deactivated->is_active);

        $reactivated = $this->service()->reactivate($this->admin, $eligibility->fresh());
        $this->assertTrue($reactivated->is_active);
    }

    // ── Duplicate / Concurrency ──────────────────────────────────────────

    public function test_exact_duplicate_assignment_is_rejected(): void
    {
        $subject = $this->subject('duplicate-v');
        $level = $this->level('duplicate-level-v');
        $curriculum = $this->curriculum($subject, $level, 'Duplicate V Curriculum');
        $system = $this->system('Duplicate System V');

        $instructor = $this->instructor();
        $this->teaches($instructor, $subject);

        $this->service()->assign($this->admin, $instructor, $system, $curriculum);

        $this->expectException(InstructorAcademicEligibilityException::class);
        $this->service()->assign($this->admin, $instructor, $system, $curriculum);

        $this->assertSame(1, InstructorCurriculumEligibility::query()
            ->where('teacher_id', $instructor->id)
            ->where('education_system_id', $system->id)
            ->where('curriculum_id', $curriculum->id)
            ->count());
    }

    public function test_database_unique_constraint_prevents_duplicate_rows_bypassing_the_service(): void
    {
        $subject = $this->subject('constraint-v');
        $level = $this->level('constraint-level-v');
        $curriculum = $this->curriculum($subject, $level, 'Constraint V Curriculum');
        $system = $this->system('Constraint System V');
        $instructor = $this->instructor();

        InstructorCurriculumEligibility::query()->create([
            'teacher_id' => $instructor->id,
            'education_system_id' => $system->id,
            'curriculum_id' => $curriculum->id,
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);
        InstructorCurriculumEligibility::query()->create([
            'teacher_id' => $instructor->id,
            'education_system_id' => $system->id,
            'curriculum_id' => $curriculum->id,
            'is_active' => true,
        ]);
    }

    public function test_concurrent_assignment_attempts_never_produce_two_active_rows(): void
    {
        $subject = $this->subject('concurrency-v');
        $level = $this->level('concurrency-level-v');
        $curriculum = $this->curriculum($subject, $level, 'Concurrency V Curriculum');
        $system = $this->system('Concurrency System V');

        $instructor = $this->instructor();
        $this->teaches($instructor, $subject);

        $attempts = 0;
        $failures = 0;

        for ($i = 0; $i < 3; $i++) {
            $attempts++;

            try {
                $this->service()->assign($this->admin, $instructor, $system, $curriculum);
            } catch (InstructorAcademicEligibilityException) {
                $failures++;
            }
        }

        $this->assertSame(3, $attempts);
        $this->assertSame(2, $failures);
        $this->assertSame(1, InstructorCurriculumEligibility::query()
            ->where('teacher_id', $instructor->id)
            ->where('education_system_id', $system->id)
            ->where('curriculum_id', $curriculum->id)
            ->count());
    }

    // ── Authorization ────────────────────────────────────────────────────

    public function test_unauthorized_user_cannot_assign_eligibility(): void
    {
        $subject = $this->subject('auth-v');
        $level = $this->level('auth-level-v');
        $curriculum = $this->curriculum($subject, $level, 'Auth V Curriculum');
        $system = $this->system('Auth System V');

        $instructor = $this->instructor();
        $this->teaches($instructor, $subject);

        $unauthorized = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->expectException(AuthorizationException::class);
        $this->service()->assign($unauthorized, $instructor, $system, $curriculum);
    }

    public function test_instructor_cannot_self_approve(): void
    {
        $subject = $this->subject('self-approve-v');
        $level = $this->level('self-approve-level-v');
        $curriculum = $this->curriculum($subject, $level, 'Self Approve V Curriculum');
        $system = $this->system('Self Approve System V');

        $instructor = $this->instructor();
        $this->teaches($instructor, $subject);

        $this->expectException(AuthorizationException::class);
        $this->service()->assign($instructor, $instructor, $system, $curriculum);
    }

    public function test_student_cannot_mutate_eligibility(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $subject = $this->subject('student-v');
        $level = $this->level('student-level-v');
        $curriculum = $this->curriculum($subject, $level, 'Student V Curriculum');
        $system = $this->system('Student System V');

        $instructor = $this->instructor();
        $this->teaches($instructor, $subject);

        $eligibility = $this->service()->assign($this->admin, $instructor, $system, $curriculum);

        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        $this->expectException(AuthorizationException::class);
        $this->service()->deactivate($student, $eligibility);
    }

    public function test_non_instructor_user_cannot_receive_eligibility(): void
    {
        $subject = $this->subject('non-instructor-v');
        $level = $this->level('non-instructor-level-v');
        $curriculum = $this->curriculum($subject, $level, 'Non Instructor V Curriculum');
        $system = $this->system('Non Instructor System V');

        $notInstructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->expectException(InstructorAcademicEligibilityException::class);
        $this->service()->assign($this->admin, $notInstructor, $system, $curriculum);
    }
}
