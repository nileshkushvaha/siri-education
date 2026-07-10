<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Booking\Contracts\TeacherCandidateRepositoryInterface;
use App\Models\AcademicLevel;
use App\Models\InstructorSubjectTopic;
use App\Models\SubjectTopic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorSubjectTopicTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
    }

    public function test_instructor_can_be_assigned_to_a_subject_topic(): void
    {
        $topic = SubjectTopic::factory()->create();
        $coverage = InstructorSubjectTopic::factory()->create([
            'teacher_id' => $this->teacher->id,
            'subject_topic_id' => $topic->id,
        ]);

        $this->assertTrue($coverage->teacher->is($this->teacher));
        $this->assertTrue($coverage->topic->is($topic));
        $this->assertSame($topic->subject_id, $coverage->subject_id);
        $this->assertCount(1, $this->teacher->instructorSubjectTopics);
    }

    public function test_instructor_can_cover_multiple_topics_under_the_same_subject(): void
    {
        $algebra = SubjectTopic::factory()->create(['name' => 'Algebra', 'slug' => 'algebra']);
        $geometry = SubjectTopic::factory()->create([
            'subject_id' => $algebra->subject_id,
            'name' => 'Geometry',
            'slug' => 'geometry',
        ]);

        InstructorSubjectTopic::factory()->create(['teacher_id' => $this->teacher->id, 'subject_topic_id' => $algebra->id]);
        InstructorSubjectTopic::factory()->create(['teacher_id' => $this->teacher->id, 'subject_topic_id' => $geometry->id]);

        $repo = app(TeacherCandidateRepositoryInterface::class);
        $this->assertTrue($repo->teachesTopic($this->teacher->id, $algebra, null));
        $this->assertTrue($repo->teachesTopic($this->teacher->id, $geometry, null));
    }

    public function test_coverage_can_be_level_specific(): void
    {
        $level = AcademicLevel::create([
            'name' => 'Middle School',
            'slug' => 'middle-school',
            'min_grade' => 6,
            'max_grade' => 8,
        ]);
        $topic = SubjectTopic::factory()->create();

        InstructorSubjectTopic::factory()->create([
            'teacher_id' => $this->teacher->id,
            'subject_topic_id' => $topic->id,
            'academic_level_id' => $level->id,
        ]);

        $repo = app(TeacherCandidateRepositoryInterface::class);
        $this->assertTrue($repo->teachesTopic($this->teacher->id, $topic, 7));   // inside 6-8
        $this->assertFalse($repo->teachesTopic($this->teacher->id, $topic, 10)); // outside 6-8
        $this->assertTrue($repo->teachesTopic($this->teacher->id, $topic, null)); // no grade constraint given
    }

    public function test_null_level_coverage_means_all_levels(): void
    {
        $topic = SubjectTopic::factory()->create();
        InstructorSubjectTopic::factory()->create([
            'teacher_id' => $this->teacher->id,
            'subject_topic_id' => $topic->id,
            'academic_level_id' => null,
        ]);

        $repo = app(TeacherCandidateRepositoryInterface::class);
        $this->assertTrue($repo->teachesTopic($this->teacher->id, $topic, 3));
        $this->assertTrue($repo->teachesTopic($this->teacher->id, $topic, 12));
    }

    public function test_inactive_or_unapproved_coverage_is_not_bookable(): void
    {
        $inactiveTopic = SubjectTopic::factory()->create(['slug' => 'inactive-cov']);
        $unapprovedTopic = SubjectTopic::factory()->create(['slug' => 'unapproved-cov']);

        InstructorSubjectTopic::factory()->inactive()->create([
            'teacher_id' => $this->teacher->id,
            'subject_topic_id' => $inactiveTopic->id,
        ]);
        InstructorSubjectTopic::factory()->unapproved()->create([
            'teacher_id' => $this->teacher->id,
            'subject_topic_id' => $unapprovedTopic->id,
        ]);

        $repo = app(TeacherCandidateRepositoryInterface::class);
        $this->assertFalse($repo->teachesTopic($this->teacher->id, $inactiveTopic, null));
        $this->assertFalse($repo->teachesTopic($this->teacher->id, $unapprovedTopic, null));
    }

    public function test_topic_coverage_grants_no_student_pricing_permissions(): void
    {
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'ViewAny:StudentLessonPrice', 'guard_name' => 'web']);
        $this->teacher->assignRole('instructor');

        InstructorSubjectTopic::factory()->create(['teacher_id' => $this->teacher->id]);

        $this->assertFalse($this->teacher->fresh()->can('ViewAny:StudentLessonPrice'));
        $this->actingAs($this->teacher)->get('/admin/student-lesson-prices')->assertForbidden();
    }
}
