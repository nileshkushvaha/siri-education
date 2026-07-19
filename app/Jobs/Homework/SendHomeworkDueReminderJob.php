<?php

declare(strict_types=1);

namespace App\Jobs\Homework;

use App\Enums\StudentStatus;
use App\Homework\Enums\HomeworkReminderStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkDueReminder;
use App\Models\UserProfile;
use App\Notifications\Homework\HomeworkDueReminderNotification;
use App\Services\AuditTrailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 24K — GAP-020 Step 14: sends one already-claimed reminder.
 * Carries only the reminder's ID (cheap re-hydration on retry); every
 * fact needed to decide whether to actually send is re-read fresh from
 * the database, never trusted from serialized state. Stale/superseded
 * work (already resolved, homework completed, due date changed,
 * student no longer Active) exits as a successful no-op or a recorded
 * Skip — never an exception, never a duplicate claim.
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

    public function handle(AuditTrailService $audit): void
    {
        $prepared = DB::transaction(function () use ($audit): ?array {
            /** @var HomeworkDueReminder|null $reminder */
            $reminder = HomeworkDueReminder::query()->whereKey($this->reminderId)->lockForUpdate()->first();

            if ($reminder === null || $reminder->status !== HomeworkReminderStatus::Pending) {
                // Already resolved by a previous attempt (or genuinely
                // does not exist) — a successful no-op, not a failure.
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

        // Never hold the transaction open while contacting a provider.
        try {
            $assignment->student->notify(new HomeworkDueReminderNotification($assignment));
        } catch (Throwable $e) {
            report($e);

            throw $e; // let the queue retry per $tries/$backoff
        }

        DB::transaction(function () use ($reminder): void {
            $fresh = HomeworkDueReminder::query()->whereKey($reminder->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->status !== HomeworkReminderStatus::Pending) {
                return;
            }

            $fresh->forceFill([
                'status' => HomeworkReminderStatus::Dispatched,
                'resolved_at' => now(),
                'attempts' => $fresh->attempts + 1,
            ])->save();
        });

        $audit->logSystem(
            'homework',
            'due_reminder_dispatched',
            'Homework due-date reminder dispatched.',
            $assignment,
            ['reminder_id' => $reminder->id, 'offset_minutes' => $reminder->reminder_offset_minutes],
        );
    }

    /**
     * Final-attempt failure: no more automatic retries remain. Recorded
     * as a genuinely visible operational failure — a safe category
     * only, never the exception message (which may echo provider
     * response bodies).
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
                'attempts' => $reminder->attempts + 1,
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
