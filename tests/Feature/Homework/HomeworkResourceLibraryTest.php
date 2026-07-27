<?php

declare(strict_types=1);

namespace Tests\Feature\Homework;

use App\Enums\AcademicStatus;
use App\Enums\InstructorStatus;
use App\Enums\LearningGoalStatus;
use App\Enums\LearningPlanStatus;
use App\Enums\StudentStatus;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Homework\Enums\HomeworkResourceStatus;
use App\Homework\Exceptions\HomeworkException;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Booking;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkResource;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS-7-8 (§7.15 "Resource Library"): categorized,
 * searchable, versioned, reusable-across-lessons instructor resources.
 * Distinct from HomeworkAssignment's own direct attachments, which
 * this suite leaves untouched.
 */
final class HomeworkResourceLibraryTest extends TestCase
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
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        Storage::fake('local');

        $this->instructor = $this->instructor();
        $this->student = $this->activeStudent();
        $this->homework = app(HomeworkServiceInterface::class);
    }

    // ── Resource creation and ownership ───────────────────────────────

    public function test_instructor_can_create_a_resource(): void
    {
        $resource = $this->homework->createResource($this->instructor, ['title' => 'Algebra Basics']);

        $this->assertSame($this->instructor->id, $resource->instructor_id);
        $this->assertSame(HomeworkResourceStatus::Active, $resource->status);
    }

    public function test_student_cannot_create_a_resource(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->homework->createResource($this->student, ['title' => 'Nope']);
    }

    public function test_resource_metadata_requires_an_active_subject_and_level(): void
    {
        $category = AcademicCategory::query()->create(['name' => 'General', 'slug' => 'general-'.uniqid()]);
        $inactiveSubject = Subject::query()->create([
            'academic_category_id' => $category->id,
            'name' => 'Retired Subject',
            'slug' => 'retired-'.uniqid(),
            'status' => AcademicStatus::Inactive,
        ]);

        try {
            $this->homework->createResource($this->instructor, ['title' => 'X', 'subject_id' => $inactiveSubject->id]);
            $this->fail('Expected HomeworkException.');
        } catch (HomeworkException $e) {
            $this->assertStringContainsString('subject', $e->getMessage());
        }

        $inactiveLevel = AcademicLevel::query()->create(['name' => 'Retired Level', 'slug' => 'retired-level-'.uniqid(), 'status' => AcademicStatus::Inactive]);

        $this->expectException(HomeworkException::class);
        $this->homework->createResource($this->instructor, ['title' => 'X', 'academic_level_id' => $inactiveLevel->id]);
    }

    // ── Immutable versioning ───────────────────────────────────────────

    public function test_publishing_a_new_version_never_touches_an_earlier_version(): void
    {
        $resource = $this->homework->createResource($this->instructor, ['title' => 'Worksheet']);
        $v1 = $this->homework->publishResourceVersion($this->instructor, $resource, $this->pdf('v1.pdf'));
        $v2 = $this->homework->publishResourceVersion($this->instructor, $resource->fresh(), $this->pdf('v2.pdf'));

        $this->assertSame(1, $v1->version_number);
        $this->assertSame(2, $v2->version_number);
        $this->assertNotSame($v1->id, $v2->id);

        $v1Fresh = $v1->fresh();
        $this->assertSame('v1.pdf', $v1Fresh->getFirstMedia('file')->file_name);
        $this->assertSame('v2.pdf', $v2->fresh()->getFirstMedia('file')->file_name);
    }

    public function test_version_numbers_are_sequential_and_unique_per_resource(): void
    {
        $resource = $this->homework->createResource($this->instructor, ['title' => 'Worksheet']);

        for ($i = 1; $i <= 3; $i++) {
            $version = $this->homework->publishResourceVersion($this->instructor, $resource->fresh(), $this->pdf("v{$i}.pdf"));
            $this->assertSame($i, $version->version_number);
        }

        $this->assertSame(3, $resource->fresh()->versions()->count());
    }

    public function test_duplicate_version_number_is_rejected_by_the_database(): void
    {
        $resource = $this->homework->createResource($this->instructor, ['title' => 'Worksheet']);
        $this->homework->publishResourceVersion($this->instructor, $resource->fresh(), $this->pdf());

        $this->expectException(QueryException::class);

        DB::table('homework_resource_versions')->insert([
            'id' => (string) Str::uuid(),
            'homework_resource_id' => $resource->id,
            'version_number' => 1,
            'created_by' => $this->instructor->id,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_only_the_owner_can_publish_a_new_version(): void
    {
        $resource = $this->homework->createResource($this->instructor, ['title' => 'Worksheet']);
        $other = $this->instructor();

        $this->expectException(AuthorizationException::class);

        $this->homework->publishResourceVersion($other, $resource, $this->pdf());
    }

    // ── Reuse across multiple assignments / snapshot preservation ─────

    public function test_the_same_version_can_be_attached_to_multiple_assignments(): void
    {
        $resource = $this->homework->createResource($this->instructor, ['title' => 'Worksheet']);
        $version = $this->homework->publishResourceVersion($this->instructor, $resource->fresh(), $this->pdf());

        $studentB = $this->activeStudent();
        $assignmentA = $this->assignment();
        $assignmentB = $this->assignment(student: $studentB);

        $this->homework->attachResourceVersion($this->instructor, $assignmentA, $version);
        $this->homework->attachResourceVersion($this->instructor, $assignmentB, $version);

        $this->assertSame(1, $assignmentA->fresh()->resourceVersions()->count());
        $this->assertSame(1, $assignmentB->fresh()->resourceVersions()->count());
        $this->assertSame(2, $version->fresh()->assignments()->count());
    }

    public function test_assignment_keeps_its_original_version_after_a_new_one_is_published(): void
    {
        $resource = $this->homework->createResource($this->instructor, ['title' => 'Worksheet']);
        $v1 = $this->homework->publishResourceVersion($this->instructor, $resource->fresh(), $this->pdf('v1.pdf'));
        $assignment = $this->assignment();

        $this->homework->attachResourceVersion($this->instructor, $assignment, $v1);

        // A new version is published after attaching — the assignment's
        // link must keep pointing at v1's exact historical content.
        $this->homework->publishResourceVersion($this->instructor, $resource->fresh(), $this->pdf('v2.pdf'));

        $attached = $assignment->fresh()->resourceVersions()->sole();
        $this->assertSame(1, $attached->version_number);
        $this->assertSame('v1.pdf', $attached->getFirstMedia('file')->file_name);
    }

    public function test_duplicate_assignment_version_link_is_prevented(): void
    {
        $resource = $this->homework->createResource($this->instructor, ['title' => 'Worksheet']);
        $version = $this->homework->publishResourceVersion($this->instructor, $resource->fresh(), $this->pdf());
        $assignment = $this->assignment();

        $this->homework->attachResourceVersion($this->instructor, $assignment, $version);

        try {
            $this->homework->attachResourceVersion($this->instructor, $assignment->fresh(), $version->fresh());
            $this->fail('Expected HomeworkException.');
        } catch (HomeworkException $e) {
            $this->assertStringContainsString('already attached', $e->getMessage());
        }

        // The database-level guarantee behind the service check.
        $this->expectException(QueryException::class);
        DB::table('homework_assignment_resources')->insert([
            'homework_assignment_id' => $assignment->id,
            'homework_resource_version_id' => $version->id,
            'attached_by' => $this->instructor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_detaching_removes_the_link_and_allows_reattachment(): void
    {
        $resource = $this->homework->createResource($this->instructor, ['title' => 'Worksheet']);
        $version = $this->homework->publishResourceVersion($this->instructor, $resource->fresh(), $this->pdf());
        $assignment = $this->assignment();

        $this->homework->attachResourceVersion($this->instructor, $assignment, $version);
        $this->homework->detachResourceVersion($this->instructor, $assignment->fresh(), $version->fresh());

        $this->assertSame(0, $assignment->fresh()->resourceVersions()->count());

        // Re-attaching after a clean detach is allowed.
        $this->homework->attachResourceVersion($this->instructor, $assignment->fresh(), $version->fresh());
        $this->assertSame(1, $assignment->fresh()->resourceVersions()->count());
    }

    // ── Archive behavior ───────────────────────────────────────────────

    public function test_archived_resource_cannot_receive_new_versions_or_be_newly_attached(): void
    {
        $resource = $this->homework->createResource($this->instructor, ['title' => 'Worksheet']);
        $version = $this->homework->publishResourceVersion($this->instructor, $resource->fresh(), $this->pdf());

        $this->homework->archiveResource($this->instructor, $resource->fresh());

        try {
            $this->homework->publishResourceVersion($this->instructor, $resource->fresh(), $this->pdf('v2.pdf'));
            $this->fail('Expected HomeworkException.');
        } catch (HomeworkException $e) {
            $this->assertStringContainsString('Archived', $e->getMessage());
        }

        $assignment = $this->assignment();

        $this->expectException(HomeworkException::class);
        $this->expectExceptionMessage('Archived resources cannot be newly attached');

        $this->homework->attachResourceVersion($this->instructor, $assignment, $version->fresh());
    }

    public function test_historical_downloads_remain_available_after_archiving(): void
    {
        $resource = $this->homework->createResource($this->instructor, ['title' => 'Worksheet']);
        $version = $this->homework->publishResourceVersion($this->instructor, $resource->fresh(), $this->pdf());
        $assignment = $this->assignment();
        $this->homework->attachResourceVersion($this->instructor, $assignment, $version);

        $this->homework->archiveResource($this->instructor, $resource->fresh());

        $this->actingAs($this->student)
            ->get(route('dashboard.homework.resources.download', $version->fresh()->getFirstMedia('file')))
            ->assertOk();
    }

    // ── Homework / learning-plan lifecycle restrictions ────────────────

    public function test_attach_and_detach_are_blocked_once_homework_is_graded(): void
    {
        $resource = $this->homework->createResource($this->instructor, ['title' => 'Worksheet']);
        $version = $this->homework->publishResourceVersion($this->instructor, $resource->fresh(), $this->pdf());
        $assignment = $this->assignment();
        $this->homework->attachResourceVersion($this->instructor, $assignment, $version);

        $this->homework->submit($assignment->fresh(), 'My answer.');
        $this->homework->review($assignment->fresh(), 'Well done.', 'A');

        $graded = $assignment->fresh();

        $version2 = $this->homework->publishResourceVersion($this->instructor, $resource->fresh(), $this->pdf('v2.pdf'));

        try {
            $this->homework->attachResourceVersion($this->instructor, $graded, $version2);
            $this->fail('Expected HomeworkException.');
        } catch (HomeworkException $e) {
            $this->assertStringContainsString('graded', $e->getMessage());
        }

        $this->expectException(HomeworkException::class);
        $this->homework->detachResourceVersion($this->instructor, $graded, $version->fresh());
    }

    public function test_attach_is_blocked_once_the_linked_learning_plan_is_archived(): void
    {
        $plan = $this->plan();
        $assignment = $this->assignment(learningPlanId: $plan->id, bookingId: null);
        $resource = $this->homework->createResource($this->instructor, ['title' => 'Worksheet']);
        $version = $this->homework->publishResourceVersion($this->instructor, $resource->fresh(), $this->pdf());

        $plan->forceFill(['status' => LearningPlanStatus::Archived, 'archived_at' => now()])->save();

        $this->expectException(HomeworkException::class);
        $this->expectExceptionMessage('completed or archived learning plan');

        $this->homework->attachResourceVersion($this->instructor, $assignment->fresh(), $version);
    }

    // ── Authorization ──────────────────────────────────────────────────

    public function test_unrelated_instructor_cannot_attach_someone_elses_resource(): void
    {
        $resource = $this->homework->createResource($this->instructor, ['title' => 'Worksheet']);
        $version = $this->homework->publishResourceVersion($this->instructor, $resource->fresh(), $this->pdf());

        $otherInstructor = $this->instructor();
        $otherAssignment = $this->assignment(instructor: $otherInstructor);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('own library');

        $this->homework->attachResourceVersion($otherInstructor, $otherAssignment, $version);
    }

    public function test_unrelated_instructor_cannot_view_or_download_someone_elses_library(): void
    {
        $resource = $this->homework->createResource($this->instructor, ['title' => 'Worksheet']);
        $version = $this->homework->publishResourceVersion($this->instructor, $resource->fresh(), $this->pdf());
        $otherInstructor = $this->instructor();

        $this->actingAs($otherInstructor)
            ->get(route('dashboard.homework.resources.download', $version->getFirstMedia('file')))
            ->assertForbidden();
    }

    public function test_student_can_view_a_version_only_through_their_own_assignment(): void
    {
        $resource = $this->homework->createResource($this->instructor, ['title' => 'Worksheet']);
        $version = $this->homework->publishResourceVersion($this->instructor, $resource->fresh(), $this->pdf());
        $assignment = $this->assignment();
        $this->homework->attachResourceVersion($this->instructor, $assignment, $version);

        $this->actingAs($this->student)
            ->get(route('dashboard.homework.resources.download', $version->fresh()->getFirstMedia('file')))
            ->assertOk();

        $otherStudent = $this->activeStudent();
        $this->actingAs($otherStudent)
            ->get(route('dashboard.homework.resources.download', $version->fresh()->getFirstMedia('file')))
            ->assertForbidden();
    }

    public function test_super_admin_bypasses_the_library_policy_via_gate_before(): void
    {
        // The download ROUTE itself is frontend-portal-only (super_admin
        // belongs to the Admin Portal and is correctly never routed
        // there — see PortalResolver/EnsureSupportedFrontendPortalAudience,
        // same as every other dashboard download route in this app).
        // The "explicit admin permission" in requirement #7 is a POLICY
        // guarantee (Gate::before), verified directly here.
        $resource = $this->homework->createResource($this->instructor, ['title' => 'Worksheet']);
        $version = $this->homework->publishResourceVersion($this->instructor, $resource->fresh(), $this->pdf());

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        $this->assertTrue($admin->can('view', $version->fresh()));
    }

    // ── Audit masking ──────────────────────────────────────────────────

    public function test_library_lifecycle_events_are_audited_without_file_content_or_storage_paths(): void
    {
        $resource = $this->homework->createResource($this->instructor, ['title' => 'Worksheet']);
        $version = $this->homework->publishResourceVersion($this->instructor, $resource->fresh(), $this->pdf());
        $assignment = $this->assignment();
        $this->homework->attachResourceVersion($this->instructor, $assignment, $version);
        $this->homework->detachResourceVersion($this->instructor, $assignment->fresh(), $version->fresh());
        $this->homework->archiveResource($this->instructor, $resource->fresh());

        foreach ([
            'homework_resource_library_created',
            'homework_resource_version_published',
            'homework_resource_version_attached',
            'homework_resource_version_detached',
            'homework_resource_archived',
        ] as $event) {
            $activity = Activity::query()->where('log_name', 'homework')->where('event', $event)->sole();
            $this->assertStringNotContainsString('/private/', $activity->properties->toJson());
            $this->assertStringNotContainsString('%PDF', $activity->properties->toJson());
        }
    }

    // ── Bounded queries ──────────────────────────────────────────────

    public function test_library_listing_query_is_bounded_regardless_of_version_count(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $resource = $this->homework->createResource($this->instructor, ['title' => "Resource {$i}"]);
            $this->homework->publishResourceVersion($this->instructor, $resource->fresh(), $this->pdf());
            $this->homework->publishResourceVersion($this->instructor, $resource->fresh(), $this->pdf('second.pdf'));
        }

        DB::enableQueryLog();
        $resources = HomeworkResource::query()
            ->forInstructor($this->instructor->id)
            ->active()
            ->with(['subject', 'academicLevel', 'versions.media'])
            ->paginate(10);
        foreach ($resources as $resource) {
            $resource->versions->each(fn ($v) => $v->getFirstMedia('file'));
        }
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(6, $count);
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

    private function plan(): StudentLearningPlan
    {
        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);
        $subject = Subject::query()->firstOrCreate(
            ['slug' => 'maths'],
            ['academic_category_id' => $category->id, 'name' => 'Maths', 'status' => 'active'],
        );

        $goal = StudentLearningGoal::query()->create([
            'user_id' => $this->student->id,
            'subject_id' => $subject->id,
            'title' => 'Master algebra',
            'type' => 'academic',
            'status' => LearningGoalStatus::Active,
        ]);

        return StudentLearningPlan::query()->create([
            'student_user_id' => $this->student->id,
            'learning_goal_id' => $goal->id,
            'primary_instructor_user_id' => $this->instructor->id,
            'subject_id' => $subject->id,
            'title' => 'Algebra plan',
            'status' => LearningPlanStatus::Active,
            'progress_percent' => 0,
        ]);
    }

    private function assignment(
        ?string $bookingId = null,
        ?int $learningPlanId = null,
        ?User $student = null,
        ?User $instructor = null,
    ): HomeworkAssignment {
        $student ??= $this->student;
        $instructor ??= $this->instructor;

        if ($bookingId === null && $learningPlanId === null) {
            $bookingId = $this->completedBooking($instructor, $student)->id;
        }

        return $this->homework->assign(
            $instructor,
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
}
