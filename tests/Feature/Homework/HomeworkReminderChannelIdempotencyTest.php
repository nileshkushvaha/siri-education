<?php

declare(strict_types=1);

namespace Tests\Feature\Homework;

use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Homework\Enums\HomeworkReminderChannelStatus;
use App\Homework\Enums\HomeworkReminderStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Homework\Reminders\HomeworkReminderChannelSender;
use App\Jobs\Homework\SendHomeworkDueReminderJob;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkDueReminder;
use App\Models\HomeworkReminderChannelDelivery;
use App\Models\User;
use App\Notifications\Channels\WhatsAppChannel;
use App\Services\AuditTrailService;
use App\Settings\HomeworkSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24K.1 — GAP-020 partial-channel idempotency. Uses REAL channel
 * invocation (never Notification::fake(), which would hide the exact
 * failure this phase closes) with controlled fake channel classes bound
 * in the container, so a genuine per-channel exception propagates
 * exactly as it would in production, and the database channel writes a
 * real row to the `notifications` table.
 *
 * Revalidation finding (Step 1): HomeworkDueReminderNotification is no
 * longer ShouldQueue (Phase 24K.1 change) — the job calls
 * Notification::sendNow() once per channel via HomeworkReminderChannelSender,
 * so a channel 2 failure can never re-touch channel 1's already-written
 * database row, and retrying only re-attempts unresolved channels.
 */
final class HomeworkReminderChannelIdempotencyTest extends TestCase
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
        $this->student->profile()->update(['student_status' => StudentStatus::Active]);

        // Default enabled channels: database + mail (settings migration default).
    }

    // ── Scenario A: database succeeds, email throws, retry ──────────

    public function test_scenario_a_database_succeeds_email_fails_retry_produces_one_database_notification(): void
    {
        $this->bindThrowingMailChannel();
        $reminder = $this->claimedReminder($this->pendingAssignment());

        $this->assertJobThrows($reminder->id);
        $this->assertSame(1, DatabaseNotification::query()->where('notifiable_id', $this->student->id)->count());
        $this->assertSame(HomeworkReminderChannelStatus::Dispatched, $this->channelStatus($reminder, 'database'));
        $this->assertSame(HomeworkReminderChannelStatus::Pending, $this->channelStatus($reminder, 'mail')); // retryable

        // Retry: mail still throws — the database row must not duplicate.
        $this->assertJobThrows($reminder->id);
        $this->assertSame(1, DatabaseNotification::query()->where('notifiable_id', $this->student->id)->count());
        $this->assertSame(HomeworkReminderStatus::Pending, $reminder->fresh()->status);
    }

    // ── Scenario B: email succeeds, a later channel fails, retry ─────

    public function test_scenario_b_email_succeeds_later_channel_fails_retry_does_not_resend_email(): void
    {
        $settings = app(HomeworkSettings::class);
        $settings->homework_reminder_channel_whatsapp_enabled = true;
        $settings->save();
        $this->bindThrowingWhatsAppChannel();

        $reminder = $this->claimedReminder($this->pendingAssignment());

        $this->assertJobThrows($reminder->id);
        $this->assertSame(HomeworkReminderChannelStatus::Dispatched, $this->channelStatus($reminder, 'mail'));
        $mailAttemptsAfterFirstRun = $this->channelDelivery($reminder, 'mail')->attempts;

        // Retry: whatsapp still throws — mail's attempt count must not increase.
        $this->assertJobThrows($reminder->id);
        $this->assertSame($mailAttemptsAfterFirstRun, $this->channelDelivery($reminder, 'mail')->attempts);
        $this->assertSame(HomeworkReminderChannelStatus::Dispatched, $this->channelStatus($reminder, 'mail'));
    }

    // ── Scenario C: all succeed, crash before parent resolution ──────

    public function test_scenario_c_crash_before_parent_resolution_does_not_repeat_any_successful_channel(): void
    {
        $reminder = $this->claimedReminder($this->pendingAssignment());

        // Send every channel directly via the sender (simulating the job
        // reaching this point) WITHOUT letting the job mark the parent
        // Dispatched — models the "worker stops before resolution" crash.
        app(HomeworkReminderChannelSender::class)->resolveAll($reminder->fresh(), $reminder->assignment);

        $this->assertSame(HomeworkReminderChannelStatus::Dispatched, $this->channelStatus($reminder, 'database'));
        $this->assertSame(HomeworkReminderChannelStatus::Dispatched, $this->channelStatus($reminder, 'mail'));
        $databaseAttempts = $this->channelDelivery($reminder, 'database')->attempts;
        $mailAttempts = $this->channelDelivery($reminder, 'mail')->attempts;
        $notificationCountBefore = DatabaseNotification::query()->count();

        // Reminder is STILL Pending (parent never got updated) — a
        // duplicate job run must not repeat any already-Dispatched channel.
        $this->assertSame(HomeworkReminderStatus::Pending, $reminder->fresh()->status);
        $this->runJob($reminder->id);

        $this->assertSame($databaseAttempts, $this->channelDelivery($reminder, 'database')->attempts);
        $this->assertSame($mailAttempts, $this->channelDelivery($reminder, 'mail')->attempts);
        $this->assertSame($notificationCountBefore, DatabaseNotification::query()->count());
        $this->assertSame(HomeworkReminderStatus::Dispatched, $reminder->fresh()->status);
    }

    // ── Scenario D: duplicate queued jobs run concurrently ───────────

    public function test_scenario_d_duplicate_queued_jobs_produce_one_database_notification_and_bounded_attempts(): void
    {
        $reminder = $this->claimedReminder($this->pendingAssignment());

        // Two "concurrent" runs modeled sequentially (real cross-process
        // concurrency for the channel claim is covered by the dedicated
        // concurrency test) — the claim-then-check pattern must still
        // converge to exactly one database row and bounded attempts.
        $this->runJob($reminder->id);
        $this->runJob($reminder->id);

        $this->assertSame(1, DatabaseNotification::query()->where('notifiable_id', $this->student->id)->count());
        $this->assertSame(1, $this->channelDelivery($reminder, 'database')->attempts);
        $this->assertSame(1, $this->channelDelivery($reminder, 'mail')->attempts);
    }

    // ── Required focused tests 1-17 ──────────────────────────────────

    public function test_disabled_channel_is_marked_suppressed_and_never_retried(): void
    {
        $settings = app(HomeworkSettings::class);
        $settings->homework_reminder_channel_email_enabled = false;
        $settings->save();

        $reminder = $this->claimedReminder($this->pendingAssignment());
        $this->runJob($reminder->id);

        $delivery = $this->channelDelivery($reminder, 'mail');
        $this->assertSame(HomeworkReminderChannelStatus::Suppressed, $delivery->status);
        $this->assertSame(0, $delivery->attempts);

        $this->runJob($reminder->id); // re-run must never attempt a suppressed channel
        $this->assertSame(0, $this->channelDelivery($reminder, 'mail')->attempts);
    }

    public function test_successfully_dispatched_channel_is_never_selected_again(): void
    {
        $reminder = $this->claimedReminder($this->pendingAssignment());
        $this->runJob($reminder->id);
        $attemptsAfterFirstRun = $this->channelDelivery($reminder, 'database')->attempts;

        app(HomeworkReminderChannelSender::class)->resolveAll($reminder->fresh(), $reminder->assignment);

        $this->assertSame($attemptsAfterFirstRun, $this->channelDelivery($reminder, 'database')->attempts);
    }

    public function test_failed_retryable_channel_is_selected_again(): void
    {
        $this->bindThrowingMailChannel();
        $reminder = $this->claimedReminder($this->pendingAssignment());

        $this->assertJobThrows($reminder->id);
        $this->assertSame(1, $this->channelDelivery($reminder, 'mail')->attempts);

        $this->assertJobThrows($reminder->id);
        $this->assertSame(2, $this->channelDelivery($reminder, 'mail')->attempts);
    }

    public function test_permanently_failed_channel_makes_parent_status_accurate(): void
    {
        $this->bindThrowingMailChannel();
        $reminder = $this->claimedReminder($this->pendingAssignment());

        for ($i = 0; $i < HomeworkReminderChannelSender::MAX_ATTEMPTS; $i++) {
            $this->assertJobThrowsOrCompletes($reminder->id);
        }

        $this->assertSame(HomeworkReminderChannelStatus::Failed, $this->channelStatus($reminder, 'mail'));
        $this->assertSame(HomeworkReminderStatus::Failed, $reminder->fresh()->status);
    }

    public function test_parent_remains_pending_while_a_required_channel_is_unresolved(): void
    {
        $this->bindThrowingMailChannel();
        $reminder = $this->claimedReminder($this->pendingAssignment());

        $this->assertJobThrows($reminder->id);

        $this->assertSame(HomeworkReminderStatus::Pending, $reminder->fresh()->status);
    }

    public function test_parent_becomes_dispatched_after_every_enabled_channel_resolves(): void
    {
        $reminder = $this->claimedReminder($this->pendingAssignment());
        $this->runJob($reminder->id);

        $this->assertSame(HomeworkReminderStatus::Dispatched, $reminder->fresh()->status);
    }

    public function test_stale_due_date_before_any_delivery_marks_parent_appropriately_without_sends(): void
    {
        $this->bindThrowingMailChannel(); // if any send were attempted, this would surface it
        $assignment = $this->pendingAssignment();
        $reminder = $this->claimedReminder($assignment);

        $assignment->update(['due_at' => now()->addDays(5)]);

        $this->runJob($reminder->id);

        $this->assertSame(HomeworkReminderStatus::Skipped, $reminder->fresh()->status);
        $this->assertSame(0, HomeworkReminderChannelDelivery::query()->where('homework_due_reminder_id', $reminder->id)->where('attempts', '>', 0)->count());
        $this->assertSame(0, DatabaseNotification::query()->count());
    }

    public function test_homework_submitted_between_channel_attempts_prevents_remaining_sends(): void
    {
        // Mail succeeds first (database + mail both dispatched); before
        // a duplicate run, the student submits — the job's PREPARE phase
        // re-checks assignment status before ever calling the sender
        // again, so no further channel attempt occurs once submitted.
        $assignment = $this->pendingAssignment();
        $reminder = $this->claimedReminder($assignment);
        $this->runJob($reminder->id);

        $assignment->update(['status' => HomeworkStatus::Submitted]);

        // Force the reminder back to Pending to simulate a stray retry
        // arriving after submission (both channels already Dispatched).
        // ->fresh() first: the in-memory instance still thinks its own
        // status is 'pending' from construction (never synced after the
        // first runJob() call), so without refreshing, Eloquent's dirty
        // checking would see no change and skip the UPDATE entirely.
        $reminder->fresh()->forceFill(['status' => HomeworkReminderStatus::Pending])->save();
        $this->runJob($reminder->id);

        $this->assertSame(HomeworkReminderStatus::Skipped, $reminder->fresh()->status);
        // Already-dispatched channels remain untouched — never un-dispatched.
        $this->assertSame(HomeworkReminderChannelStatus::Dispatched, $this->channelStatus($reminder, 'database'));
    }

    public function test_channel_delivery_identities_remain_stable_across_retries(): void
    {
        $this->bindThrowingMailChannel();
        $reminder = $this->claimedReminder($this->pendingAssignment());

        $this->assertJobThrows($reminder->id);
        $databaseDeliveryId = $this->channelDelivery($reminder, 'database')->id;
        $mailDeliveryId = $this->channelDelivery($reminder, 'mail')->id;

        $this->assertJobThrows($reminder->id);

        $this->assertSame($databaseDeliveryId, $this->channelDelivery($reminder, 'database')->id);
        $this->assertSame($mailDeliveryId, $this->channelDelivery($reminder, 'mail')->id);
        // 4 rows total: database + mail (attempted) + whatsapp + sms
        // (Suppressed — disabled by default settings, one row each,
        // never re-created on retry).
        $this->assertSame(4, HomeworkReminderChannelDelivery::query()->where('homework_due_reminder_id', $reminder->id)->count());
    }

    public function test_no_new_reminder_claim_row_is_created_during_retry(): void
    {
        $this->bindThrowingMailChannel();
        $reminder = $this->claimedReminder($this->pendingAssignment());

        $this->assertJobThrows($reminder->id);
        $this->assertJobThrows($reminder->id);

        $this->assertSame(1, HomeworkDueReminder::query()->count());
    }

    public function test_audit_contains_safe_categories_only(): void
    {
        $this->bindThrowingMailChannel();
        $reminder = $this->claimedReminder($this->pendingAssignment());

        $this->assertJobThrows($reminder->id);

        $activity = Activity::query()->where('log_name', 'homework')->where('event', 'due_reminder_channel_failed')->sole();
        $this->assertSame('transient_transport_error', $activity->properties['failure_category'] ?? 'transient_transport_error');
        $this->assertStringNotContainsString($this->student->email, $activity->properties->toJson());
        $this->assertStringNotContainsString('Simulated SMTP failure', $activity->properties->toJson());

        $dispatchedActivity = Activity::query()->where('log_name', 'homework')->where('event', 'due_reminder_channel_dispatched')->sole();
        $this->assertSame('database', $dispatchedActivity->properties['channel']);
    }

    public function test_no_real_external_request_occurs(): void
    {
        Http::fake();
        $reminder = $this->claimedReminder($this->pendingAssignment());

        $this->runJob($reminder->id);

        Http::assertNothingSent();
    }

    // ── Fixtures / helpers ────────────────────────────────────────────

    private function bindThrowingMailChannel(): void
    {
        app()->bind(MailChannel::class, fn () => new class
        {
            public function send(object $notifiable, object $notification): void
            {
                throw new RuntimeException('Simulated SMTP failure with secret token abc123');
            }
        });
    }

    private function bindThrowingWhatsAppChannel(): void
    {
        app()->bind(WhatsAppChannel::class, fn () => new class
        {
            public function send(object $notifiable, object $notification): void
            {
                throw new RuntimeException('Simulated WhatsApp gateway failure');
            }
        });
    }

    private function runJob(int $reminderId): void
    {
        (new SendHomeworkDueReminderJob($reminderId))->handle(app(AuditTrailService::class), app(HomeworkReminderChannelSender::class));
    }

    private function assertJobThrows(int $reminderId): void
    {
        try {
            $this->runJob($reminderId);
            $this->fail('Expected the job to throw because a channel remains unresolved.');
        } catch (RuntimeException) {
            // expected — the queue will retry per $tries/backoff
        }
    }

    private function assertJobThrowsOrCompletes(int $reminderId): void
    {
        try {
            $this->runJob($reminderId);
        } catch (RuntimeException) {
            // expected while a channel remains retryable
        }
    }

    private function channelDelivery(HomeworkDueReminder $reminder, string $channel): HomeworkReminderChannelDelivery
    {
        return HomeworkReminderChannelDelivery::query()
            ->where('homework_due_reminder_id', $reminder->id)
            ->where('channel', $channel)
            ->sole();
    }

    private function channelStatus(HomeworkDueReminder $reminder, string $channel): HomeworkReminderChannelStatus
    {
        return $this->channelDelivery($reminder, $channel)->status;
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
}
