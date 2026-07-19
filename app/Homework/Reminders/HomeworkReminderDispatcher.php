<?php

declare(strict_types=1);

namespace App\Homework\Reminders;

use App\Enums\StudentStatus;
use App\Homework\Enums\HomeworkReminderStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Jobs\Homework\SendHomeworkDueReminderJob;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkDueReminder;
use App\Models\UserProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 24K — GAP-020 Step 8: atomically claims a single reminder
 * identity for one candidate. Revalidates the assignment fresh, under
 * a row lock, before ever attempting to claim — a candidate that fails
 * revalidation here is skipped and no row is written at all (distinct
 * from the queued job's own last-moment revalidation in Step 10, which
 * runs against an ALREADY-claimed row). The unique index on
 * homework_due_reminders is the actual concurrency guarantee: a lost
 * race on insert is treated as "already claimed," never a duplicate
 * send. No provider/queue dispatch happens while the transaction is open.
 */
final class HomeworkReminderDispatcher
{
    public const string CLAIMED = 'claimed';

    public const string ALREADY_CLAIMED = 'already_claimed';

    public const string SKIPPED = 'skipped';

    public function claimAndDispatch(HomeworkAssignment $candidate, int $offsetHours): string
    {
        $reminderId = null;

        $outcome = DB::transaction(function () use ($candidate, $offsetHours, &$reminderId): string {
            /** @var HomeworkAssignment|null $assignment */
            $assignment = HomeworkAssignment::query()->whereKey($candidate->id)->lockForUpdate()->first();

            if (! $this->isEligible($assignment, $offsetHours)) {
                return self::SKIPPED;
            }

            try {
                $reminder = HomeworkDueReminder::query()->create([
                    'homework_assignment_id' => $assignment->id,
                    'recipient_user_id' => $assignment->student_id,
                    'due_at_snapshot' => $assignment->due_at,
                    'reminder_offset_minutes' => $offsetHours * 60,
                    'status' => HomeworkReminderStatus::Pending,
                    'attempts' => 0,
                    'claimed_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return self::ALREADY_CLAIMED;
            }

            $reminderId = $reminder->id;

            return self::CLAIMED;
        });

        if ($outcome === self::CLAIMED && $reminderId !== null) {
            SendHomeworkDueReminderJob::dispatch($reminderId)->afterCommit();
        }

        return $outcome;
    }

    private function isEligible(?HomeworkAssignment $assignment, int $offsetHours): bool
    {
        if ($assignment === null || $assignment->due_at === null) {
            return false;
        }

        if ($assignment->status !== HomeworkStatus::Pending) {
            return false;
        }

        $now = now();

        // Fresh recompute against the just-locked row: a due date moved
        // later since the candidate was selected no longer meets this
        // offset's threshold; a due date already passed is overdue and
        // must not receive a pre-due reminder (Step 7).
        if ($assignment->due_at <= $now || $assignment->due_at > $now->copy()->addHours($offsetHours)) {
            return false;
        }

        $studentStatus = UserProfile::query()->where('user_id', $assignment->student_id)->value('student_status');

        return $studentStatus === StudentStatus::Active;
    }
}
