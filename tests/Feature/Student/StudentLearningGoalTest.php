<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Enums\AcademicStatus;
use App\Enums\LearningGoalStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Subject;
use App\Models\User;
use App\Services\Student\StudentLearningGoalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentLearningGoalTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Subject $subject;

    private AcademicLevel $level;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');
        $this->student->profile()->update(['student_status' => StudentStatus::Active]); // interactive student actions require Active status.

        $category = AcademicCategory::create(['name' => 'Mathematics', 'slug' => 'mathematics']);
        $this->subject = Subject::create(['academic_category_id' => $category->id, 'name' => 'Algebra', 'slug' => 'algebra']);
        $this->level = AcademicLevel::create(['name' => 'High School', 'slug' => 'high-school']);
    }

    public function test_student_creates_updates_completes_and_archives_own_goal(): void
    {
        $service = app(StudentLearningGoalService::class);

        $goal = $service->create($this->student, [
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'title' => 'Master algebra',
            'type' => 'academic',
            'target_date' => now()->addMonth()->toDateString(),
        ]);

        $this->assertSame(LearningGoalStatus::Active, $goal->status);
        $this->assertSame($this->subject->id, $goal->subject_id);
        $this->assertDatabaseHas('activity_log', ['event' => 'student_learning_goal_created']);

        $goal = $service->update($this->student, $goal, [
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'title' => 'Master algebra and graphs',
            'type' => 'academic',
        ]);

        $this->assertSame('Master algebra and graphs', $goal->title);

        $completed = $service->complete($this->student, $goal);
        $this->assertSame(LearningGoalStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);

        $archived = $service->archive($this->student, $completed);
        $this->assertSame(LearningGoalStatus::Archived, $archived->status);
        $this->assertNotNull($archived->archived_at);
    }

    public function test_academic_and_exam_goals_require_academic_level(): void
    {
        $this->expectException(ValidationException::class);

        app(StudentLearningGoalService::class)->create($this->student, [
            'subject_id' => $this->subject->id,
            'title' => 'Prepare for exam',
            'type' => 'exam_preparation',
        ]);
    }

    public function test_personal_goal_allows_missing_academic_level(): void
    {
        $goal = app(StudentLearningGoalService::class)->create($this->student, [
            'subject_id' => $this->subject->id,
            'title' => 'Learn for fun',
            'type' => 'personal',
        ]);

        $this->assertNull($goal->academic_level_id);
    }

    public function test_invalid_or_inactive_master_data_is_rejected(): void
    {
        $this->subject->update(['status' => AcademicStatus::Archived]);

        $this->expectException(ValidationException::class);

        app(StudentLearningGoalService::class)->create($this->student, [
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'title' => 'Use archived subject',
            'type' => 'academic',
        ]);
    }

    public function test_student_cannot_update_another_students_goal(): void
    {
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $other->assignRole('student');
        $other->profile()->update(['student_status' => StudentStatus::Active]); // interactive student actions require Active status.

        $goal = app(StudentLearningGoalService::class)->create($other, [
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'title' => 'Other goal',
            'type' => 'academic',
        ]);

        $this->expectException(AuthorizationException::class);

        app(StudentLearningGoalService::class)->update($this->student, $goal, [
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'title' => 'Steal goal',
            'type' => 'academic',
        ]);
    }
}
