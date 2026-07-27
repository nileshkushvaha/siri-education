<?php

declare(strict_types=1);

namespace App\Jobs\Homework;

use App\Enums\StudentStatus;
use App\Homework\Enums\HomeworkReminderChannelStatus;
use App\Homework\Enums\HomeworkReminderStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Homework\Reminders\HomeworkReminderChannelSender;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkDueReminder;
use App\Models\UserProfile;
use App\Services\AuditTrailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Sends one already-claimed reminder. Carries only the reminder's ID
 * (cheap re-hydration on retry); every fact needed to decide whether to
 * send is re-read fresh from the database, never trusted from
 * serialized state.
 *
 * Delivery itself is delegated per-channel to HomeworkReminderChannelSender,
 * which is the actual idempotency authority — this job's
 * own $tries/backoff only control HOW OFTEN this orchestration re-runs;
 * they never cause a channel that already succeeded to resend, because
 * HomeworkReminderChannelSender re-checks each channel's own durable
 * state before ever attempting it again.
 */
final class SendHomeworkDueReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120];

    public function __construct(public readonly int $reminderId)
    {
        $this->onQueue('notifications');
    }

    public function handle(AuditTrailService $audit, HomeworkReminderChannelSender $sender): void
    {
        $prepared = DB::transaction(function () use ($audit): ?array {
            /** @var HomeworkDueReminder|null $reminder */
            $reminder = HomeworkDueReminder::query()->whereKey($this->reminderId)->lockForUpdate()->first();

            if ($reminder === null || $reminder->status !== HomeworkReminderStatus::Pending) {
                // Already fully resolved (Dispatched/Skipped/Failed) by a
                // previous attempt, or genuinely does not exist — a
                // successful no-op, never a duplicate claim or send.
                return null;
            }

            /** @var HomeworkAssignment|null $assignment */
            $assignment = HomeworkAssignment::query()->whereKey($reminder->homework_assignment_id)->lockForUpdate()->first();

            $skipReason = $this->skipReason($assignment, $reminder);

            if ($skipReason !== null) {
                $reminder->forceFill([
                    'status' => HomeworkReminderStatus::Skipped,
                    'failure_category' => $skipReason,
                    'resolved_at' => now(),
                ])->save();

                // No channel attempts should occur once the parent has
                // been decided ineligible — resolve any not-yet-attempted
                // channel rows as Suppressed too, so nothing lingers Pending.
                $reminder->channelDeliveries()
                    ->whereIn('status', [HomeworkReminderChannelStatus::Pending, HomeworkReminderChannelStatus::Sending])
                    ->update(['status' => HomeworkReminderChannelStatus::Suppressed->value, 'resolved_at' => now()]);

                $audit->logSystem(
                    'homework',
                    'due_reminder_skipped',
                    'Homework due-date reminder skipped.',
                    $assignment,
                    ['reminder_id' => $reminder->id, 'offset_minutes' => $reminder->reminder_offset_minutes, 'reason' => $skipReason],
                );

                return null;
            }

            return ['assignment' => $assignment, 'reminder' => $reminder];
        });

        if ($prepared === null) {
            return;
        }

        /** @var HomeworkAssignment $assignment */
        $assignment = $prepared['assignment'];
        /** @var HomeworkDueReminder $reminder */
        $reminder = $prepared['reminder'];

        // Per-channel: never holds a transaction open during delivery,
        // never resends an already-Dispatched/Suppressed channel.
        $sender->resolveAll($reminder, $assignment);

        $aggregate = $sender->aggregateStatus($reminder);

        DB::transaction(function () use ($reminder, $aggregate, $audit): void {
            $fresh = HomeworkDueReminder::query()->whereKey($reminder->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->status !== HomeworkReminderStatus::Pending) {
                return;
            }

            $status = match ($aggregate) {
                'dispatched' => HomeworkReminderStatus::Dispatched,
                'failed' => HomeworkReminderStatus::Failed,
                default => null, // still pending — leave the reminder Pending for a later retry
            };

            if ($status === null) {
                return;
            }

            $fresh->forceFill(['status' => $status, 'resolved_at' => now()])->save();

            $audit->logSystem(
                'homework',
                $status === HomeworkReminderStatus::Dispatched ? 'due_reminder_dispatched' : 'due_reminder_failed',
                $status === HomeworkReminderStatus::Dispatched
                    ? 'Homework due-date reminder dispatched.'
                    : 'Homework due-date reminder failed.',
                $reminder->assignment,
                ['reminder_id' => $reminder->id, 'offset_minutes' => $reminder->reminder_offset_minutes],
            );
        });

        if ($aggregate === 'pending') {
            // At least one channel is still retryable — let the queue's
            // own $tries/backoff bring this job back; already-resolved
            // channels are untouched on the next attempt.
            throw new RuntimeException('One or more homework reminder channels remain unresolved.');
        }
    }

    /**
     * Final-attempt failure of the JOB ITSELF (not a per-channel
     * failure, which never throws this far) — an unexpected error, or
     * queue-level exhaustion while a channel was still retryable. A
     * safe category only, never the exception message.
     */
    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            $reminder = HomeworkDueReminder::query()->whereKey($this->reminderId)->lockForUpdate()->first();

            if ($reminder === null || $reminder->status !== HomeworkReminderStatus::Pending) {
                return;
            }

            $reminder->forceFill([
                'status' => HomeworkReminderStatus::Failed,
                'failure_category' => 'transient_transport_error',
                'resolved_at' => now(),
            ])->save();
        });

        app(AuditTrailService::class)->logSystem(
            'homework',
            'due_reminder_failed',
            'Homework due-date reminder failed.',
            null,
            ['reminder_id' => $this->reminderId],
        );
    }

    private function skipReason(?HomeworkAssignment $assignment, HomeworkDueReminder $reminder): ?string
    {
        if ($assignment === null) {
            return 'assignment_missing';
        }

        if ($assignment->status !== HomeworkStatus::Pending) {
            return 'assignment_completed';
        }

        if ($assignment->due_at === null || ! $assignment->due_at->equalTo($reminder->due_at_snapshot)) {
            return 'stale_due_date';
        }

        if ($assignment->due_at <= now()) {
            return 'stale_due_date';
        }

        $studentStatus = UserProfile::query()->where('user_id', $assignment->student_id)->value('student_status');

        if ($studentStatus !== StudentStatus::Active) {
            return 'ineligible_student';
        }

        return null;
    }
}
