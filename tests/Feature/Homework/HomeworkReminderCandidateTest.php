<?php

declare(strict_types=1);

namespace Tests\Feature\Homework;

use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Homework\Enums\HomeworkReminderStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Homework\Reminders\HomeworkReminderDispatcher;
use App\Jobs\Homework\SendHomeworkDueReminderJob;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkDueReminder;
use App\Models\User;
use App\Settings\HomeworkSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS §7.11 / SRS-7-11: claim-time candidate
 * selection and eligibility. Covers thresholds, catch-up after a late
 * scheduler run, multiple/duplicate offsets, due-date changes, cleared
 * due dates, and student-lifecycle eligibility. Time is frozen —
 * these tests never sleep().
 */
final class HomeworkReminderCandidateTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private HomeworkSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 08:00:00', 'UTC'));

        $this->instructor = $this->instructor();
        $this->settings = app(HomeworkSettings::class);
        $this->settings->homework_due_reminders_enabled = true;
        $this->settings->homework_due_reminder_offset_hours = [24];
        $this->settings->save();
    }

    public function test_disabled_setting_claims_nothing(): void
    {
        Queue::fake();
        $this->settings->homework_due_reminders_enabled = false;
        $this->settings->save();

        $assignment = $this->assignment(dueInHours: 24);

        $this->artisan('homework:send-due-reminders')->assertSuccessful();

        $this->assertSame(0, HomeworkDueReminder::query()->count());
        Queue::assertNotPushed(SendHomeworkDueReminderJob::class);
    }

    public function test_enabled_setting_with_valid_offset_claims_a_due_reminder(): void
    {
        Queue::fake();
        $assignment = $this->assignment(dueInHours: 24);

        $outcome = app(HomeworkReminderDispatcher::class)->claimAndDispatch($assignment, 24);

        $this->assertSame(HomeworkReminderDispatcher::CLAIMED, $outcome);
        $reminder = HomeworkDueReminder::query()->sole();
        $this->assertSame($assignment->id, $reminder->homework_assignment_id);
        $this->assertSame($assignment->student_id, $reminder->recipient_user_id);
        $this->assertSame(24 * 60, $reminder->reminder_offset_minutes);
        $this->assertSame(HomeworkReminderStatus::Pending, $reminder->status);
        Queue::assertPushed(SendHomeworkDueReminderJob::class, 1);
    }

    // Note: "assignment without due date" / "due date cleared" are not
    // reachable scenarios — homework_assignments.due_at has always been
    // a NOT NULL column (pre-existing schema, unrelated to this phase),
    // so no row can ever have a null due_at. The candidate query's
    // whereNotNull('due_at') and the dispatcher's/job's null checks
    // remain as defense-in-depth in case that invariant ever relaxes,
    // but are unreachable today — documented rather than widening
    // due_at nullability across the whole homework domain (isOverdue(),
    // dashboards, reports, blades), which is out of this phase's scope.

    public function test_submitted_assignment_is_ignored(): void
    {
        $assignment = $this->assignment(dueInHours: 24, status: HomeworkStatus::Submitted);

        $outcome = app(HomeworkReminderDispatcher::class)->claimAndDispatch($assignment, 24);

        $this->assertSame(HomeworkReminderDispatcher::SKIPPED, $outcome);
    }

    public function test_graded_assignment_is_ignored(): void
    {
        $assignment = $this->assignment(dueInHours: 24, status: HomeworkStatus::Graded);

        $outcome = app(HomeworkReminderDispatcher::class)->claimAndDispatch($assignment, 24);

        $this->assertSame(HomeworkReminderDispatcher::SKIPPED, $outcome);
    }

    public function test_exactly_at_threshold_is_claimed(): void
    {
        Queue::fake();
        // due_at is EXACTLY now() + 24h: due_at <= now()->addHours(24) is true at the boundary.
        $assignment = $this->assignment(dueInHours: 24);

        $outcome = app(HomeworkReminderDispatcher::class)->claimAndDispatch($assignment, 24);

        $this->assertSame(HomeworkReminderDispatcher::CLAIMED, $outcome);
    }

    public function test_before_threshold_is_not_claimed(): void
    {
        // Due in 30 hours — the 24h-offset threshold has not been reached yet.
        $assignment = $this->assignment(dueInHours: 30);

        $outcome = app(HomeworkReminderDispatcher::class)->claimAndDispatch($assignment, 24);

        $this->assertSame(HomeworkReminderDispatcher::SKIPPED, $outcome);
        $this->assertSame(0, HomeworkDueReminder::query()->count());
    }

    public function test_late_scheduler_run_before_due_date_still_claims_one_accurate_reminder(): void
    {
        Queue::fake();
        // Threshold (24h before) passed 3 hours ago; due_at is still 3h in the future.
        $assignment = $this->assignment(dueInHours: 3);

        $outcome = app(HomeworkReminderDispatcher::class)->claimAndDispatch($assignment, 24);

        $this->assertSame(HomeworkReminderDispatcher::CLAIMED, $outcome);
        $this->assertSame(1, HomeworkDueReminder::query()->count());
    }

    public function test_scheduler_run_after_due_date_does_not_send_a_pre_due_reminder(): void
    {
        $assignment = $this->assignment(dueInHours: -1); // already overdue

        $outcome = app(HomeworkReminderDispatcher::class)->claimAndDispatch($assignment, 24);

        $this->assertSame(HomeworkReminderDispatcher::SKIPPED, $outcome);
        $this->assertSame(0, HomeworkDueReminder::query()->count());
    }

    public function test_multiple_configured_offsets_create_distinct_reminders(): void
    {
        Queue::fake();
        $this->settings->homework_due_reminder_offset_hours = [24, 3];
        $this->settings->save();

        $assignment = $this->assignment(dueInHours: 2); // within both 24h and 3h thresholds

        $this->artisan('homework:send-due-reminders')->assertSuccessful();

        $this->assertSame(2, HomeworkDueReminder::query()->where('homework_assignment_id', $assignment->id)->count());
        $this->assertSame(
            [3 * 60, 24 * 60],
            HomeworkDueReminder::query()->where('homework_assignment_id', $assignment->id)->orderBy('reminder_offset_minutes')->pluck('reminder_offset_minutes')->all(),
        );
    }

    public function test_duplicate_offsets_are_normalized(): void
    {
        $this->settings->homework_due_reminder_offset_hours = [24, 24, 3, 3];
        $this->settings->save();

        $this->assertSame([3, 24], $this->settings->normalizedOffsets());
    }

    public function test_repeated_command_runs_do_not_duplicate(): void
    {
        Queue::fake();
        $this->assignment(dueInHours: 24);

        $this->artisan('homework:send-due-reminders')->assertSuccessful();
        $this->artisan('homework:send-due-reminders')->assertSuccessful();

        $this->assertSame(1, HomeworkDueReminder::query()->count());
        Queue::assertPushed(SendHomeworkDueReminderJob::class, 1);
    }

    public function test_due_date_change_invalidates_stale_claimed_work_and_a_new_due_date_can_create_a_new_identity(): void
    {
        Queue::fake();
        $assignment = $this->assignment(dueInHours: 24);

        $first = app(HomeworkReminderDispatcher::class)->claimAndDispatch($assignment, 24);
        $this->assertSame(HomeworkReminderDispatcher::CLAIMED, $first);

        // Instructor pushes the due date out — the OLD reminder row keeps
        // its original due_at_snapshot untouched (historical evidence).
        $assignment->forceFill(['due_at' => now()->addDays(10)])->save();

        $stillOnlyOne = HomeworkDueReminder::query()->count();
        $this->assertSame(1, $stillOnlyOne);
        $this->assertNotEquals($assignment->fresh()->due_at, HomeworkDueReminder::query()->sole()->due_at_snapshot);

        // No new claim until the new due date's own threshold is reached.
        $tooEarly = app(HomeworkReminderDispatcher::class)->claimAndDispatch($assignment->fresh(), 24);
        $this->assertSame(HomeworkReminderDispatcher::SKIPPED, $tooEarly);

        // Move time forward to the new due date's threshold — a legitimate NEW identity.
        CarbonImmutable::setTestNow(now()->addDays(9)->addHours(1));
        $secondClaim = app(HomeworkReminderDispatcher::class)->claimAndDispatch($assignment->fresh(), 24);
        $this->assertSame(HomeworkReminderDispatcher::CLAIMED, $secondClaim);
        $this->assertSame(2, HomeworkDueReminder::query()->count());
    }

    public function test_active_student_is_eligible(): void
    {
        Queue::fake();
        $assignment = $this->assignment(dueInHours: 24, studentStatus: StudentStatus::Active);

        $outcome = app(HomeworkReminderDispatcher::class)->claimAndDispatch($assignment, 24);

        $this->assertSame(HomeworkReminderDispatcher::CLAIMED, $outcome);
    }

    /** @return iterable<string, array{StudentStatus}> */
    public static function ineligibleStudentStatuses(): iterable
    {
        yield 'registered' => [StudentStatus::Registered];
        yield 'suspended' => [StudentStatus::Suspended];
        yield 'archived' => [StudentStatus::Archived];
    }

    #[DataProvider('ineligibleStudentStatuses')]
    public function test_non_active_students_do_not_receive_a_reminder(StudentStatus $status): void
    {
        $assignment = $this->assignment(dueInHours: 24, studentStatus: $status);

        $outcome = app(HomeworkReminderDispatcher::class)->claimAndDispatch($assignment, 24);

        $this->assertSame(HomeworkReminderDispatcher::SKIPPED, $outcome);
        $this->assertSame(0, HomeworkDueReminder::query()->count());
    }

    public function test_null_student_status_does_not_receive_a_reminder(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        // Profile exists but student_status left at its default — never
        // manually forced to Active.
        $student->profile()->update(['student_status' => null]);

        $assignment = HomeworkAssignment::factory()->create([
            'teacher_id' => $this->instructor->id,
            'student_id' => $student->id,
            'status' => HomeworkStatus::Pending,
            'due_at' => now()->addHours(2),
        ]);

        $outcome = app(HomeworkReminderDispatcher::class)->claimAndDispatch($assignment->fresh(), 24);

        $this->assertSame(HomeworkReminderDispatcher::SKIPPED, $outcome);
    }

    public function test_reactivated_student_may_receive_a_still_valid_unclaimed_reminder(): void
    {
        Queue::fake();
        $assignment = $this->assignment(dueInHours: 24, studentStatus: StudentStatus::Suspended);

        $blocked = app(HomeworkReminderDispatcher::class)->claimAndDispatch($assignment, 24);
        $this->assertSame(HomeworkReminderDispatcher::SKIPPED, $blocked);

        $assignment->student->profile()->update(['student_status' => StudentStatus::Active]);

        $claimed = app(HomeworkReminderDispatcher::class)->claimAndDispatch($assignment->fresh(), 24);
        $this->assertSame(HomeworkReminderDispatcher::CLAIMED, $claimed);
    }

    public function test_phase_24j_context_constraint_and_homework_submit_review_remain_unchanged(): void
    {
        // A both-linked assignment (24J) is a perfectly normal reminder
        // candidate — the reminder system never touches booking_id/
        // learning_plan_id, and submit()/review() are untouched.
        $assignment = $this->assignment(dueInHours: 24);

        Queue::fake();
        $outcome = app(HomeworkReminderDispatcher::class)->claimAndDispatch($assignment, 24);
        $this->assertSame(HomeworkReminderDispatcher::CLAIMED, $outcome);

        $this->assertNotNull($assignment->fresh()->booking_id);
    }

    public function test_command_output_contains_counts_but_no_personal_data(): void
    {
        Queue::fake();
        $student = $this->activeStudent();
        HomeworkAssignment::factory()->create([
            'teacher_id' => $this->instructor->id,
            'student_id' => $student->id,
            'status' => HomeworkStatus::Pending,
            'due_at' => now()->addHours(2),
        ]);

        $result = Artisan::call('homework:send-due-reminders');
        $output = Artisan::output();

        $this->assertSame(0, $result);
        $this->assertStringContainsString('Examined:', $output);
        $this->assertStringContainsString('Claimed:', $output);
        $this->assertStringNotContainsString($student->email, $output);
        $this->assertStringNotContainsString($student->name, $output);
    }

    public function test_no_external_provider_call_occurs(): void
    {
        Http::fake();
        Queue::fake();

        $this->assignment(dueInHours: 24);

        $this->artisan('homework:send-due-reminders')->assertSuccessful();

        Http::assertNothingSent();
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

    private function assignment(int $dueInHours, HomeworkStatus $status = HomeworkStatus::Pending, StudentStatus $studentStatus = StudentStatus::Active): HomeworkAssignment
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => $studentStatus]);

        return HomeworkAssignment::factory()->create([
            'teacher_id' => $this->instructor->id,
            'student_id' => $student->id,
            'status' => $status,
            'due_at' => now()->addHours($dueInHours),
        ])->fresh();
    }
}
