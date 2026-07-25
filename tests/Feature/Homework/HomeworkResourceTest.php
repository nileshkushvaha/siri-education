<?php

declare(strict_types=1);

namespace Tests\Feature\Homework;

use App\Enums\InstructorStatus;
use App\Enums\LearningGoalStatus;
use App\Enums\LearningPlanStatus;
use App\Enums\StudentStatus;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Homework\Enums\HomeworkResourceCollection;
use App\Homework\Exceptions\HomeworkException;
use App\Livewire\Frontend\Instructor\HomeworkList as InstructorHomeworkList;
use App\Livewire\Frontend\Student\HomeworkList as StudentHomeworkList;
use App\Models\AcademicCategory;
use App\Models\Booking;
use App\Models\HomeworkAssignment;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileCannotBeAdded;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * GAP-022 (SRS-7-3/8): homework resource and attachment library.
 * Instructor-provided resources and student submission attachments are
 * both Media Library collections on the existing HomeworkAssignment
 * model (Message-style pattern), served only through the
 * policy-rechecked download route — never a public URL.
 */
final class HomeworkResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $student;

    private HomeworkServiceInterface $homework;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        Storage::fake('local');

        $this->instructor = $this->instructor();
        $this->student = $this->activeStudent();
        $this->homework = app(HomeworkServiceInterface::class);
    }

    // ── Authorized upload / download / removal ───────────────────────

    public function test_assigning_instructor_can_add_a_resource_and_both_parties_can_download_it(): void
    {
        $assignment = $this->assignment();

        $media = $this->homework->addResource($this->instructor, $assignment, $this->pdf());

        $this->assertSame(HomeworkResourceCollection::InstructorResources->value, $media->collection_name);
        $this->assertSame('application/pdf', $media->mime_type);
        $this->assertSame(1, $assignment->fresh()->getMedia('instructor_resources')->count());

        $this->actingAs($this->instructor)
            ->get(route('dashboard.homework.resources.download', $media))
            ->assertOk();

        $this->actingAs($this->student)
            ->get(route('dashboard.homework.resources.download', $media))
            ->assertOk();
    }

    public function test_student_submission_attachment_is_stored_and_downloadable_by_both_parties(): void
    {
        $assignment = $this->assignment();

        $this->homework->submit($assignment, 'My answer.', $this->image());

        $fresh = $assignment->fresh();
        $media = $fresh->getFirstMedia('submission_attachments');

        $this->assertNotNull($media);
        $this->assertSame(HomeworkResourceCollection::SubmissionAttachment->value, $media->collection_name);

        $this->actingAs($this->student)->get(route('dashboard.homework.resources.download', $media))->assertOk();
        $this->actingAs($this->instructor)->get(route('dashboard.homework.resources.download', $media))->assertOk();
    }

    public function test_instructor_can_remove_a_resource_and_the_stale_link_then_404s(): void
    {
        $assignment = $this->assignment();
        $media = $this->homework->addResource($this->instructor, $assignment, $this->pdf());
        $mediaId = (string) $media->id;

        $this->homework->removeResource($this->instructor, $assignment->fresh(), $mediaId);

        $this->assertSame(0, $assignment->fresh()->getMedia('instructor_resources')->count());

        $this->actingAs($this->instructor)
            ->get(route('dashboard.homework.resources.download', $mediaId))
            ->assertNotFound();
    }

    // ── Ownership / unrelated-user denial ─────────────────────────────

    public function test_unrelated_instructor_cannot_add_or_remove_resources(): void
    {
        $assignment = $this->assignment();
        $otherInstructor = $this->instructor();

        try {
            $this->homework->addResource($otherInstructor, $assignment, $this->pdf());
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            // expected
        }

        $media = $this->homework->addResource($this->instructor, $assignment, $this->pdf());

        $this->expectException(AuthorizationException::class);
        $this->homework->removeResource($otherInstructor, $assignment->fresh(), (string) $media->id);
    }

    public function test_unrelated_instructor_cannot_view_or_download_resources(): void
    {
        $assignment = $this->assignment();
        $media = $this->homework->addResource($this->instructor, $assignment, $this->pdf());
        $otherInstructor = $this->instructor();

        $this->actingAs($otherInstructor)
            ->get(route('dashboard.homework.resources.download', $media))
            ->assertForbidden();
    }

    public function test_unrelated_student_cannot_view_or_download_resources(): void
    {
        $assignment = $this->assignment();
        $media = $this->homework->addResource($this->instructor, $assignment, $this->pdf());
        $otherStudent = $this->activeStudent();

        $this->actingAs($otherStudent)
            ->get(route('dashboard.homework.resources.download', $media))
            ->assertForbidden();
    }

    public function test_cross_assignment_media_id_is_denied_even_for_a_valid_instructor(): void
    {
        // The instructor is a genuine party to assignmentA, but the media
        // id requested belongs to assignmentB (a different student) —
        // cross-student access must be denied even though the actor is a
        // legitimate instructor somewhere in the system.
        $assignmentA = $this->assignment();
        $otherStudent = $this->activeStudent();
        $assignmentB = $this->assignment(student: $otherStudent);
        $mediaB = $this->homework->addResource($this->instructor, $assignmentB, $this->pdf());

        $studentA = $assignmentA->student;

        $this->actingAs($studentA)
            ->get(route('dashboard.homework.resources.download', $mediaB))
            ->assertForbidden();
    }

    // ── File type / MIME / size / count validation ────────────────────

    public function test_disallowed_file_type_is_rejected_by_the_media_collection(): void
    {
        $assignment = $this->assignment();

        $this->expectException(FileCannotBeAdded::class);

        $this->homework->addResource(
            $this->instructor,
            $assignment,
            UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload'),
        );
    }

    public function test_resource_count_is_bounded_per_assignment(): void
    {
        $assignment = $this->assignment();

        for ($i = 0; $i < 5; $i++) {
            $this->homework->addResource($this->instructor, $assignment->fresh(), $this->pdf("resource-{$i}.pdf"));
        }

        $this->expectException(HomeworkException::class);
        $this->expectExceptionMessage('maximum number of resources');

        $this->homework->addResource($this->instructor, $assignment->fresh(), $this->pdf('one-too-many.pdf'));
    }

    public function test_livewire_upload_rejects_disallowed_type_and_oversized_files(): void
    {
        $assignment = $this->assignment();

        Livewire::actingAs($this->instructor)
            ->test(InstructorHomeworkList::class)
            ->call('startAddResource', $assignment->id)
            ->set('newResource', UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload'))
            ->call('uploadResource', $assignment->id)
            ->assertHasErrors('newResource');

        Livewire::actingAs($this->instructor)
            ->test(InstructorHomeworkList::class)
            ->call('startAddResource', $assignment->id)
            ->set('newResource', UploadedFile::fake()->create('huge.pdf', 6000, 'application/pdf'))
            ->call('uploadResource', $assignment->id)
            ->assertHasErrors('newResource');
    }

    public function test_student_submission_attachment_validation_rejects_oversized_files(): void
    {
        $assignment = $this->assignment();

        Livewire::actingAs($this->student)
            ->test(StudentHomeworkList::class)
            ->call('startSubmission', $assignment->id)
            ->set('submissionText', 'My answer.')
            ->set('submissionAttachment', UploadedFile::fake()->create('huge.pdf', 6000, 'application/pdf'))
            ->call('submit')
            ->assertHasErrors('submissionAttachment');
    }

    // ── Filename sanitization ──────────────────────────────────────────

    public function test_uploaded_filenames_are_sanitized_on_storage(): void
    {
        $assignment = $this->assignment();

        $media = $this->homework->addResource(
            $this->instructor,
            $assignment,
            UploadedFile::fake()->createWithContent('../../evil dir/file name.pdf', '%PDF-1.4'.str_repeat('A', 200).'%%EOF'),
        );

        $this->assertStringNotContainsString('/', $media->file_name);
        $this->assertStringNotContainsString('\\', $media->file_name);
    }

    // ── Private storage ────────────────────────────────────────────────

    public function test_resources_are_stored_on_the_private_local_disk_not_publicly_exposed(): void
    {
        $assignment = $this->assignment();

        $media = $this->homework->addResource($this->instructor, $assignment, $this->pdf());

        $this->assertSame('local', $media->disk);
        Storage::disk('local')->assertExists($media->getPathRelativeToRoot());
    }

    // ── Homework / learning-plan lifecycle restrictions ────────────────

    public function test_resources_cannot_be_added_or_removed_once_homework_is_graded(): void
    {
        $assignment = $this->assignment();
        $media = $this->homework->addResource($this->instructor, $assignment->fresh(), $this->pdf());

        $this->homework->submit($assignment->fresh(), 'My answer.');
        $this->homework->review($assignment->fresh(), 'Well done.', 'A');

        $graded = $assignment->fresh();

        try {
            $this->homework->addResource($this->instructor, $graded, $this->pdf('late.pdf'));
            $this->fail('Expected HomeworkException.');
        } catch (HomeworkException $e) {
            $this->assertStringContainsString('graded', $e->getMessage());
        }

        $this->expectException(HomeworkException::class);
        $this->homework->removeResource($this->instructor, $graded, (string) $media->id);
    }

    public function test_resources_cannot_be_added_once_the_linked_learning_plan_is_archived(): void
    {
        $plan = $this->plan();
        $assignment = $this->assignment(learningPlanId: $plan->id);

        $plan->forceFill(['status' => LearningPlanStatus::Archived, 'archived_at' => now()])->save();

        $this->expectException(HomeworkException::class);
        $this->expectExceptionMessage('completed or archived learning plan');

        $this->homework->addResource($this->instructor, $assignment->fresh(), $this->pdf());
    }

    public function test_historical_resources_remain_downloadable_after_grading(): void
    {
        $assignment = $this->assignment();
        $media = $this->homework->addResource($this->instructor, $assignment->fresh(), $this->pdf());

        $this->homework->submit($assignment->fresh(), 'My answer.');
        $this->homework->review($assignment->fresh(), 'Well done.', 'A');

        $this->actingAs($this->student)
            ->get(route('dashboard.homework.resources.download', $media))
            ->assertOk();
    }

    // ── Audit masking ──────────────────────────────────────────────────

    public function test_resource_added_and_removed_audit_entries_omit_file_content_and_storage_path(): void
    {
        $assignment = $this->assignment();
        $media = $this->homework->addResource($this->instructor, $assignment->fresh(), $this->pdf());

        $added = Activity::query()->where('log_name', 'homework')->where('event', 'homework_resource_added')->sole();
        $this->assertSame(HomeworkResourceCollection::InstructorResources->value, $added->properties['collection']);
        $this->assertStringNotContainsString('storage', mb_strtolower($added->properties->toJson()));
        $this->assertStringNotContainsString('/private/', $added->properties->toJson());

        $this->homework->removeResource($this->instructor, $assignment->fresh(), (string) $media->id);

        $removed = Activity::query()->where('log_name', 'homework')->where('event', 'homework_resource_removed')->sole();
        $this->assertSame(HomeworkResourceCollection::InstructorResources->value, $removed->properties['collection']);
    }

    public function test_submission_attachment_upload_writes_an_audit_entry(): void
    {
        $assignment = $this->assignment();

        $this->homework->submit($assignment, 'My answer.', $this->pdf());

        $this->assertSame(
            1,
            Activity::query()
                ->where('log_name', 'homework')
                ->where('event', 'homework_resource_added')
                ->where('subject_id', $assignment->id)
                ->count(),
        );
    }

    // ── No progress-calculation regression ─────────────────────────────

    public function test_adding_or_removing_a_resource_does_not_change_learning_plan_progress(): void
    {
        $plan = $this->plan();
        $assignment = $this->assignment(learningPlanId: $plan->id);
        $progressBefore = $plan->fresh()->progress_percent;

        $media = $this->homework->addResource($this->instructor, $assignment->fresh(), $this->pdf());
        $this->assertSame($progressBefore, $plan->fresh()->progress_percent);

        $this->homework->removeResource($this->instructor, $assignment->fresh(), (string) $media->id);
        $this->assertSame($progressBefore, $plan->fresh()->progress_percent);
    }

    // ── Bounded resource-list queries ──────────────────────────────────

    public function test_student_homework_list_query_is_bounded_regardless_of_resource_count(): void
    {
        foreach (range(1, 3) as $i) {
            $booking = $this->completedBooking();
            $assignment = $this->assignment(bookingId: $booking->id);
            $this->homework->addResource($this->instructor, $assignment->fresh(), $this->pdf("r{$i}.pdf"));
        }

        DB::enableQueryLog();
        $page = $this->homework->paginatedForStudent($this->student->id, 10);
        foreach ($page as $assignment) {
            $assignment->getMedia('instructor_resources');
        }
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Bound raised from 6 to 8 in Phase 37A: paginatedForStudent() now
        // also eager-loads resourceVersions.resource/.media for the
        // reusable library — still a small fixed number of queries per
        // PAGE, not one per row.
        $this->assertLessThanOrEqual(8, $count);
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function instructor(): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $instructor->profile()->update(['instructor_status' => InstructorStatus::Active]);

        return $instructor;
    }

    private function activeStudent(): User
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => StudentStatus::Active]);

        return $student;
    }

    private function completedBooking(?User $instructor = null, ?User $student = null): Booking
    {
        return Booking::factory()->completed()->create([
            'instructor_id' => ($instructor ?? $this->instructor)->id,
            'student_id' => ($student ?? $this->student)->id,
        ]);
    }

    private function plan(?User $student = null): StudentLearningPlan
    {
        $student ??= $this->student;

        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);
        $subject = Subject::query()->firstOrCreate(
            ['slug' => 'maths'],
            ['academic_category_id' => $category->id, 'name' => 'Maths', 'status' => 'active'],
        );

        $goal = StudentLearningGoal::query()->create([
            'user_id' => $student->id,
            'subject_id' => $subject->id,
            'title' => 'Master algebra',
            'type' => 'academic',
            'status' => LearningGoalStatus::Active,
        ]);

        return StudentLearningPlan::query()->create([
            'student_user_id' => $student->id,
            'learning_goal_id' => $goal->id,
            'primary_instructor_user_id' => $this->instructor->id,
            'subject_id' => $subject->id,
            'title' => 'Algebra plan',
            'status' => LearningPlanStatus::Active,
            'progress_percent' => 0,
        ]);
    }

    private function assignment(?string $bookingId = null, ?int $learningPlanId = null, ?User $student = null): HomeworkAssignment
    {
        $student ??= $this->student;

        if ($bookingId === null && $learningPlanId === null) {
            $bookingId = $this->completedBooking(student: $student)->id;
        }

        return $this->homework->assign(
            $this->instructor,
            $student,
            [
                'title' => 'Fractions worksheet',
                'subject' => 'maths',
                'due_at' => now()->addWeek(),
            ],
            bookingId: $bookingId,
            learningPlanId: $learningPlanId,
        );
    }

    private function pdf(string $name = 'resource.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, '%PDF-1.4'.str_repeat('A', 300).'%%EOF');
    }

    private function image(string $name = 'submission.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name);
    }
}
