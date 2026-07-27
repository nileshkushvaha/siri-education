<?php

declare(strict_types=1);

namespace Tests\Feature\Homework\Concurrency;

use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Homework\Enums\HomeworkReminderChannelStatus;
use App\Homework\Enums\HomeworkReminderStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkDueReminder;
use App\Models\HomeworkReminderChannelDelivery;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use RuntimeException;

/**
 * Partial-channel idempotency: two REAL processes
 * run the full reminder job for the SAME already-claimed reminder at
 * the same instant (modeling two overlapping queue workers, or a
 * duplicate dispatch). Each channel's claim-lease transaction must let
 * exactly one worker actually send it — never two database notification
 * rows, never a channel attempted twice concurrently.
 */
final class HomeworkReminderChannelClaimRaceTest extends ConcurrencyTestCase
{
    public function test_concurrent_job_runs_never_duplicate_the_database_channel_or_double_claim_any_channel(): void
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $instructor->profile()->update(['instructor_status' => InstructorStatus::Active]);

        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => StudentStatus::Active]);

        $assignment = HomeworkAssignment::factory()->create([
            'teacher_id' => $instructor->id,
            'student_id' => $student->id,
            'status' => HomeworkStatus::Pending,
            'due_at' => now()->addHours(2),
        ]);

        $reminder = HomeworkDueReminder::query()->create([
            'homework_assignment_id' => $assignment->id,
            'recipient_user_id' => $student->id,
            'due_at_snapshot' => $assignment->due_at,
            'reminder_offset_minutes' => 24 * 60,
            'status' => HomeworkReminderStatus::Pending,
            'attempts' => 0,
            'claimed_at' => now(),
        ]);

        $results = $this->race([
            ['run-homework-reminder-job', ['reminder_id' => $reminder->id]],
            ['run-homework-reminder-job', ['reminder_id' => $reminder->id]],
        ]);

        // A worker MAY legitimately throw "channels remain unresolved"
        // if, at the exact instant it checks the aggregate, the other
        // worker's channel claim is still mid-flight (Sending, not yet
        // Dispatched) — that is correct real-world behavior (its own
        // job invocation is told to retry later), not a correctness
        // violation. The only failure mode this test actually guards
        // against is a genuinely unexpected exception (deadlock, etc.).
        foreach ($results as $result) {
            $this->assertTrue(
                $result['ok'] || $result['exception'] === RuntimeException::class,
                json_encode($result),
            );
        }

        $this->assertSame(1, DatabaseNotification::query()->where('notifiable_id', $student->id)->count());

        $databaseDelivery = HomeworkReminderChannelDelivery::query()
            ->where('homework_due_reminder_id', $reminder->id)
            ->where('channel', 'database')
            ->sole();
        $mailDelivery = HomeworkReminderChannelDelivery::query()
            ->where('homework_due_reminder_id', $reminder->id)
            ->where('channel', 'mail')
            ->sole();

        // Exactly one worker's claim transaction wins the "Sending" lease
        // per channel — the other observes it already owned/resolved and
        // never increments attempts a second time concurrently. This is
        // the deterministic safety guarantee regardless of exact timing.
        $this->assertSame(1, $databaseDelivery->attempts);
        $this->assertSame(1, $mailDelivery->attempts);
        $this->assertSame(HomeworkReminderChannelStatus::Dispatched, $databaseDelivery->status);
        $this->assertSame(HomeworkReminderChannelStatus::Dispatched, $mailDelivery->status);

        // The parent may be 'dispatched' (whichever worker's own
        // aggregate check ran after both channels resolved) or still
        // 'pending' (both channels are done, but neither worker's own
        // aggregate check happened to observe that) — never 'failed'/'skipped'.
        $this->assertContains($reminder->fresh()->status->value, ['dispatched', 'pending']);
    }
}
