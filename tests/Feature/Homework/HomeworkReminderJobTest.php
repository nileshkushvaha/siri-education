<?php

declare(strict_types=1);

namespace Tests\Feature\Homework;

use App\Enums\InstructorStatus;
use App\Enums\LearningGoalStatus;
use App\Enums\LearningPlanStatus;
use App\Enums\StudentStatus;
use App\Homework\Enums\HomeworkReminderStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Homework\Reminders\HomeworkReminderChannelSender;
use App\Jobs\Homework\SendHomeworkDueReminderJob;
use App\Models\AcademicCategory;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkDueReminder;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\User;
use App\Notifications\Homework\HomeworkDueReminderNotification;
use App\Services\AuditTrailService;
use App\Settings\HomeworkSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Last-moment revalidation, notification content,
 * channel/timezone behavior, and audit visibility inside the queued
 * job. This is where "claimed but no longer useful" races are caught —
 * distinct from the candidate-query tests, which cover claim-time
 * eligibility.
 */
final class HomeworkReminderJobTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 08:00:00', 'UTC'));

        $this->instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->instructor->assignRole('instructor');
        $this->instructor->profile()->update(['instructor_status' => InstructorStatus::Active]);

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');
        $this->student->profile()->update(['student_status' => StudentStatus::Active, 'timezone' => 'America/New_York']);
    }

    public function test_submission_after_claim_but_before_send_suppresses_the_notification(): void
    {
        Notification::fake();
        $reminder = $this->claimedReminder($this->pendingAssignment());

        $reminder->assignment->update(['status' => HomeworkStatus::Submitted]);

        $this->runJob($reminder->id);

        Notification::assertNothingSent();
        $fresh = $reminder->fresh();
        $this->assertSame(HomeworkReminderStatus::Skipped, $fresh->status);
        $this->assertSame('assignment_completed', $fresh->failure_category);
    }

    public function test_duplicate_queued_job_sends_each_channel_at_most_once(): void
    {
        Notification::fake();
        $reminder = $this->claimedReminder($this->pendingAssignment());

        $this->runJob($reminder->id);
        $this->runJob($reminder->id); // duplicate — the row is no longer Pending

        // Default settings enable 'database' + 'mail' — two channels,
        // each sent exactly once, never twice, across both job runs.
        Notification::assertSentTimes(HomeworkDueReminderNotification::class, 2);
        Notification::assertSentTo($this->student, HomeworkDueReminderNotification::class, fn ($n, array $channels): bool => $channels === ['database']);
        Notification::assertSentTo($this->student, HomeworkDueReminderNotification::class, fn ($n, array $channels): bool => $channels === ['mail']);
        $this->assertSame(HomeworkReminderStatus::Dispatched, $reminder->fresh()->status);
    }

    public function test_retry_preserves_the_same_durable_operation(): void
    {
        Notification::fake();
        $reminder = $this->claimedReminder($this->pendingAssignment());

        $this->runJob($reminder->id);
        $this->runJob($reminder->id);

        $this->assertSame(1, HomeworkDueReminder::query()->count());
    }

    public function test_stale_due_date_after_claim_is_skipped_not_sent(): void
    {
        Notification::fake();
        $assignment = $this->pendingAssignment();
        $reminder = $this->claimedReminder($assignment);

        $assignment->update(['due_at' => now()->addDays(5)]); // instructor changed it after claim

        $this->runJob($reminder->id);

        Notification::assertNothingSent();
        $this->assertSame(HomeworkReminderStatus::Skipped, $reminder->fresh()->status);
        $this->assertSame('stale_due_date', $reminder->fresh()->failure_category);
    }

    public function test_ineligible_student_after_claim_is_skipped_not_sent(): void
    {
        Notification::fake();
        $assignment = $this->pendingAssignment();
        $reminder = $this->claimedReminder($assignment);

        $this->student->profile()->update(['student_status' => StudentStatus::Suspended]);

        $this->runJob($reminder->id);

        Notification::assertNothingSent();
        $this->assertSame('ineligible_student', $reminder->fresh()->failure_category);
    }

    public function test_channel_preferences_are_respected(): void
    {
        Notification::fake();
        $settings = app(HomeworkSettings::class);
        $settings->homework_reminder_channel_email_enabled = false;
        $settings->homework_reminder_channel_whatsapp_enabled = false;
        $settings->homework_reminder_channel_sms_enabled = false;
        $settings->save();

        $reminder = $this->claimedReminder($this->pendingAssignment());
        $this->runJob($reminder->id);

        Notification::assertSentTo($this->student, HomeworkDueReminderNotification::class, function ($notification, array $channels): bool {
            return $channels === ['database'];
        });
    }

    public function test_all_external_channels_disabled_still_delivers_in_app_as_a_safe_suppression_of_external_delivery(): void
    {
        Notification::fake();
        $settings = app(HomeworkSettings::class);
        $settings->homework_reminder_channel_email_enabled = false;
        $settings->homework_reminder_channel_whatsapp_enabled = false;
        $settings->homework_reminder_channel_sms_enabled = false;
        $settings->save();

        $reminder = $this->claimedReminder($this->pendingAssignment());
        $this->runJob($reminder->id);

        // Not a failure: in-app delivery is never gated by the external
        // channel toggles, so "all [external] channels disabled" still
        // dispatches successfully via the database channel alone.
        $this->assertSame(HomeworkReminderStatus::Dispatched, $reminder->fresh()->status);
    }

    public function test_notification_uses_the_recipient_timezone_and_dst_transition_is_correct(): void
    {
        Notification::fake();
        // 2026-11-01 12:00 UTC is before the Nov 1 2026 US DST fallback
        // (2:00 AM local) — America/New_York is still EDT (UTC-4) here.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'));
        $assignment = $this->pendingAssignment(dueInHours: 2);
        $reminder = $this->claimedReminder($assignment);

        $this->runJob($reminder->id);

        Notification::assertSentTo($this->student, HomeworkDueReminderNotification::class, function (HomeworkDueReminderNotification $notification) use ($assignment): bool {
            $mail = $notification->toMail($this->student);
            $expected = $assignment->due_at->timezone('America/New_York')->format('D, M j Y \a\t g:i A');

            return str_contains(implode(' ', $mail->introLines), $expected);
        });
    }

    public function test_lesson_only_context_renders_safely(): void
    {
        Notification::fake();
        $assignment = $this->pendingAssignment(); // factory default is booking-linked
        $reminder = $this->claimedReminder($assignment);

        $this->runJob($reminder->id);

        Notification::assertSentTo($this->student, HomeworkDueReminderNotification::class, function (HomeworkDueReminderNotification $notification): bool {
            return str_contains(implode(' ', $notification->toMail($this->student)->introLines), 'recent lesson');
        });
    }

    public function test_plan_only_context_renders_safely(): void
    {
        Notification::fake();
        $plan = $this->plan();
        $assignment = HomeworkAssignment::factory()->create([
            'booking_id' => null,
            'learning_plan_id' => $plan->id,
            'teacher_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'status' => HomeworkStatus::Pending,
            'due_at' => now()->addHours(2),
        ]);
        $reminder = $this->claimedReminder($assignment);

        $this->runJob($reminder->id);

        Notification::assertSentTo($this->student, HomeworkDueReminderNotification::class, function (HomeworkDueReminderNotification $notification) use ($plan): bool {
            return str_contains(implode(' ', $notification->toMail($this->student)->introLines), $plan->title);
        });
    }

    public function test_both_contexts_render_safely(): void
    {
        Notification::fake();
        $plan = $this->plan();
        $assignment = $this->pendingAssignment();
        $assignment->update(['learning_plan_id' => $plan->id]);
        $reminder = $this->claimedReminder($assignment->fresh());

        $this->runJob($reminder->id);

        Notification::assertSentTo($this->student, HomeworkDueReminderNotification::class, function (HomeworkDueReminderNotification $notification) use ($plan): bool {
            return str_contains(implode(' ', $notification->toMail($this->student)->introLines), $plan->title);
        });
    }

    public function test_notification_contains_no_sensitive_data(): void
    {
        Notification::fake();
        $assignment = $this->pendingAssignment();
        $assignment->update(['submission_text' => 'Secret answer content', 'feedback' => 'Private instructor feedback']);
        $reminder = $this->claimedReminder($assignment->fresh());

        $this->runJob($reminder->id);

        Notification::assertSentTo($this->student, HomeworkDueReminderNotification::class, function (HomeworkDueReminderNotification $notification): bool {
            $mail = $notification->toMail($this->student);
            $text = implode(' ', $mail->introLines).$mail->subject;

            return ! str_contains($text, 'Secret answer content')
                && ! str_contains($text, 'Private instructor feedback')
                && ! str_contains($text, $this->instructor->email);
        });
    }

    public function test_successful_dispatch_writes_a_safe_audit_entry(): void
    {
        Notification::fake();
        $reminder = $this->claimedReminder($this->pendingAssignment());

        $this->runJob($reminder->id);

        $activity = Activity::query()->where('log_name', 'homework')->where('event', 'due_reminder_dispatched')->sole();
        $this->assertSame($reminder->id, $activity->properties['reminder_id']);
        $this->assertStringNotContainsString($this->student->email, $activity->properties->toJson());
    }

    public function test_skipped_reminder_writes_a_safe_audit_entry(): void
    {
        Notification::fake();
        $assignment = $this->pendingAssignment();
        $reminder = $this->claimedReminder($assignment);
        $assignment->update(['status' => HomeworkStatus::Graded]);

        $this->runJob($reminder->id);

        $activity = Activity::query()->where('log_name', 'homework')->where('event', 'due_reminder_skipped')->sole();
        $this->assertSame('assignment_completed', $activity->properties['reason']);
    }

    public function test_failure_is_recorded_safely_without_the_raw_exception_message(): void
    {
        $reminder = $this->claimedReminder($this->pendingAssignment());
        $job = new SendHomeworkDueReminderJob($reminder->id);

        $job->failed(new \RuntimeException('Provider responded with secret token abc123'));

        $fresh = $reminder->fresh();
        $this->assertSame(HomeworkReminderStatus::Failed, $fresh->status);
        $this->assertSame('transient_transport_error', $fresh->failure_category);

        $activity = Activity::query()->where('log_name', 'homework')->where('event', 'due_reminder_failed')->sole();
        $this->assertStringNotContainsString('secret token abc123', $activity->properties->toJson());
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function runJob(int $reminderId): void
    {
        (new SendHomeworkDueReminderJob($reminderId))->handle(app(AuditTrailService::class), app(HomeworkReminderChannelSender::class));
    }

    private function pendingAssignment(int $dueInHours = 2): HomeworkAssignment
    {
        return HomeworkAssignment::factory()->create([
            'teacher_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'status' => HomeworkStatus::Pending,
            'due_at' => now()->addHours($dueInHours),
        ])->fresh();
    }

    private function claimedReminder(HomeworkAssignment $assignment): HomeworkDueReminder
    {
        return HomeworkDueReminder::query()->create([
            'homework_assignment_id' => $assignment->id,
            'recipient_user_id' => $assignment->student_id,
            'due_at_snapshot' => $assignment->due_at,
            'reminder_offset_minutes' => 24 * 60,
            'status' => HomeworkReminderStatus::Pending,
            'attempts' => 0,
            'claimed_at' => now(),
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
}
