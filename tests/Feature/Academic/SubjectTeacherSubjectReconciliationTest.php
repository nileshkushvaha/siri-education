<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Booking\DTOs\AssignmentCriteriaData;
use App\Booking\Repositories\TeacherCandidateRepository;
use App\Enums\InstructorStatus;
use App\Models\AcademicCategory;
use App\Models\Subject;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\Instructor\InstructorService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Reconciliation: TeacherSubject.subject (free-text) stays the field
 * booking flows read; subject_id is an optional link to the Subject
 * master. Old rows (subject_id null) must keep working exactly as
 * before; new rows can link to a Subject and have services prefer it
 * for display. See docs/architecture/subject-teacher-subject-reconciliation.md.
 */
class SubjectTeacherSubjectReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    private function makeInstructor(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->profile->update(['profile_visibility' => 'public', 'instructor_status' => InstructorStatus::Approved]);
        $user->assignRole('instructor');

        return $user;
    }

    private function makeCriteria(string $subject, int $grade = 5): AssignmentCriteriaData
    {
        return new AssignmentCriteriaData(
            typeKey: 'one_on_one',
            subject: $subject,
            grade: $grade,
            startsAt: CarbonImmutable::now()->addDay(),
            durationMinutes: 30,
        );
    }

    // ── Old free-text-only rows still work ─────────────────────────────────

    public function test_old_free_text_teacher_subject_is_still_booking_eligible(): void
    {
        $instructor = $this->makeInstructor();
        TeacherSubject::create([
            'teacher_id' => $instructor->id,
            'subject' => 'maths',
            'grade_from' => 1,
            'grade_to' => 12,
        ]);

        $repository = app(TeacherCandidateRepository::class);

        $this->assertTrue($repository->isEligible($instructor->id, $this->makeCriteria('maths')));
        $this->assertCount(1, $repository->eligible($this->makeCriteria('maths')));
    }

    public function test_old_free_text_teacher_subject_has_null_subject_id_by_default(): void
    {
        $instructor = $this->makeInstructor();
        $teacherSubject = TeacherSubject::create([
            'teacher_id' => $instructor->id,
            'subject' => 'maths',
            'grade_from' => 1,
            'grade_to' => 12,
        ]);

        $this->assertNull($teacherSubject->subject_id);
        $this->assertNull($teacherSubject->subjectMaster);
    }

    public function test_instructor_service_falls_back_to_formatted_free_text_when_no_subject_link(): void
    {
        $instructor = $this->makeInstructor();
        TeacherSubject::create([
            'teacher_id' => $instructor->id,
            'subject' => 'maths',
            'grade_from' => 1,
            'grade_to' => 12,
        ]);

        $subjects = app(InstructorService::class)->subjectsFor($instructor);

        $this->assertSame('Maths', $subjects->first()['name']);
        $this->assertSame('maths', $subjects->first()['slug']);
    }

    // ── New subject_id relation works ───────────────────────────────────────

    public function test_teacher_subject_can_link_to_a_subject_master(): void
    {
        $category = AcademicCategory::create(['name' => 'Mathematics', 'slug' => 'mathematics']);
        $subject = Subject::create(['academic_category_id' => $category->id, 'name' => 'Algebra', 'slug' => 'algebra']);
        $instructor = $this->makeInstructor();

        $teacherSubject = TeacherSubject::create([
            'teacher_id' => $instructor->id,
            'subject' => 'algebra',
            'subject_id' => $subject->id,
            'grade_from' => 1,
            'grade_to' => 12,
        ]);

        $this->assertTrue($teacherSubject->subjectMaster->is($subject));
        $this->assertTrue($subject->teacherSubjects->contains($teacherSubject));
    }

    public function test_linked_teacher_subject_is_still_booking_eligible_by_free_text(): void
    {
        $category = AcademicCategory::create(['name' => 'Mathematics', 'slug' => 'mathematics']);
        $subject = Subject::create(['academic_category_id' => $category->id, 'name' => 'Algebra', 'slug' => 'algebra']);
        $instructor = $this->makeInstructor();

        TeacherSubject::create([
            'teacher_id' => $instructor->id,
            'subject' => 'algebra',
            'subject_id' => $subject->id,
            'grade_from' => 1,
            'grade_to' => 12,
        ]);

        $repository = app(TeacherCandidateRepository::class);

        $this->assertTrue($repository->isEligible($instructor->id, $this->makeCriteria('algebra')));
    }

    // ── Services prefer the Subject relation when present ───────────────────

    public function test_instructor_service_prefers_subject_master_name_when_linked(): void
    {
        $category = AcademicCategory::create(['name' => 'Mathematics', 'slug' => 'mathematics']);
        $subject = Subject::create(['academic_category_id' => $category->id, 'name' => 'Algebra I', 'slug' => 'algebra-i']);
        $instructor = $this->makeInstructor();

        TeacherSubject::create([
            'teacher_id' => $instructor->id,
            'subject' => 'algebra',
            'subject_id' => $subject->id,
            'grade_from' => 1,
            'grade_to' => 12,
        ]);

        $subjects = app(InstructorService::class)->subjectsFor($instructor);

        // Master's name ("Algebra I") wins over the raw free-text ("algebra").
        $this->assertSame('Algebra I', $subjects->first()['name']);
        $this->assertSame('algebra-i', $subjects->first()['slug']);
    }

    // ── Backfill migration ───────────────────────────────────────────────────

    /**
     * The schema change (adding subject_id) already ran once via
     * RefreshDatabase's normal migration cycle — re-invoking the whole
     * migration's up() here would re-run that ALTER TABLE, which MySQL
     * treats as an implicit commit and breaks this test's transaction
     * wrapper. So this exercises just the backfill UPDATE, the same SQL
     * the migration runs, against rows inserted after that schema change.
     */
    public function test_backfill_maps_exact_case_insensitive_name_matches(): void
    {
        $category = AcademicCategory::create(['name' => 'Languages', 'slug' => 'languages']);
        $englishSubject = Subject::create(['academic_category_id' => $category->id, 'name' => 'English', 'slug' => 'english']);

        $instructor = $this->makeInstructor();
        $matching = TeacherSubject::create([
            'teacher_id' => $instructor->id,
            'subject' => 'english', // lowercase — must still match "English" case-insensitively
            'grade_from' => 1,
            'grade_to' => 12,
        ]);
        $nonMatching = TeacherSubject::create([
            'teacher_id' => $instructor->id,
            'subject' => 'maths', // no Subject named "Maths" exists — must stay null
            'grade_from' => 1,
            'grade_to' => 12,
        ]);

        $this->runBackfill();

        $this->assertSame($englishSubject->id, $matching->fresh()->subject_id);
        $this->assertNull($nonMatching->fresh()->subject_id);
    }

    public function test_backfill_is_idempotent_and_does_not_overwrite_existing_links(): void
    {
        $category = AcademicCategory::create(['name' => 'Languages', 'slug' => 'languages']);
        $english = Subject::create(['academic_category_id' => $category->id, 'name' => 'English', 'slug' => 'english']);
        $french = Subject::create(['academic_category_id' => $category->id, 'name' => 'French', 'slug' => 'french']);

        $instructor = $this->makeInstructor();
        $teacherSubject = TeacherSubject::create([
            'teacher_id' => $instructor->id,
            'subject' => 'english',
            'subject_id' => $french->id, // deliberately pre-linked to a different subject
            'grade_from' => 1,
            'grade_to' => 12,
        ]);

        $this->runBackfill();

        // The backfill only targets rows WHERE subject_id IS NULL — an
        // already-linked row (however it got linked) is left alone.
        $this->assertSame($french->id, $teacherSubject->fresh()->subject_id);
        $this->assertNotSame($english->id, $teacherSubject->fresh()->subject_id);
    }

    /**
     * The same backfill SQL as the migration's up() — run standalone (DML
     * only, no ALTER TABLE) so it's safe to call from within a test
     * transaction. Kept in lockstep with
     * database/migrations/2026_07_09_100000_add_subject_id_to_teacher_subjects_table.php.
     */
    private function runBackfill(): void
    {
        DB::update('
            UPDATE teacher_subjects
            INNER JOIN subjects ON LOWER(subjects.name) = LOWER(teacher_subjects.subject)
            SET teacher_subjects.subject_id = subjects.id
            WHERE teacher_subjects.subject_id IS NULL
        ');
    }
}
