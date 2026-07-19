<?php

declare(strict_types=1);

namespace App\Console\Commands\Homework;

use App\Homework\Reminders\HomeworkReminderCandidateQuery;
use App\Homework\Reminders\HomeworkReminderDispatcher;
use App\Settings\HomeworkSettings;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 24K — GAP-020 Step 15: safe-to-rerun scheduler sweep. For each
 * admin-configured offset, streams candidates in bounded chunks and
 * claims each one individually — one candidate's failure never aborts
 * the batch. Output is counts only, no personal data. Exit code is
 * non-zero only for a genuine operational failure (an exception
 * outside the per-record isolation, e.g. a broken query); per-record
 * skip/already-claimed outcomes are normal and always exit 0.
 */
class SendHomeworkDueReminders extends Command
{
    protected $signature = 'homework:send-due-reminders';

    protected $description = 'Claim and dispatch homework due-date reminders for each configured offset';

    public function handle(HomeworkSettings $settings, HomeworkReminderCandidateQuery $candidates, HomeworkReminderDispatcher $dispatcher): int
    {
        if (! $settings->homework_due_reminders_enabled) {
            $this->info('Homework due-date reminders are disabled — nothing to do.');

            return self::SUCCESS;
        }

        $offsets = $settings->normalizedOffsets();

        if ($offsets === []) {
            $this->warn('Homework due-date reminders are enabled but no offsets are configured.');

            return self::SUCCESS;
        }

        $examined = 0;
        $claimed = 0;
        $alreadyClaimed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($offsets as $offsetHours) {
            $candidates->forOffset($offsetHours)->chunkById(100, function ($chunk) use ($offsetHours, $dispatcher, &$examined, &$claimed, &$alreadyClaimed, &$skipped, &$failed): void {
                foreach ($chunk as $candidate) {
                    $examined++;

                    try {
                        match ($dispatcher->claimAndDispatch($candidate, $offsetHours)) {
                            HomeworkReminderDispatcher::CLAIMED => $claimed++,
                            HomeworkReminderDispatcher::ALREADY_CLAIMED => $alreadyClaimed++,
                            HomeworkReminderDispatcher::SKIPPED => $skipped++,
                        };
                    } catch (Throwable $e) {
                        $failed++;
                        report($e);
                    }
                }
            }, 'homework_assignments.id', 'id');
        }

        $this->info(sprintf(
            'Examined: %d, Claimed: %d, Already claimed: %d, Skipped: %d, Failed: %d',
            $examined,
            $claimed,
            $alreadyClaimed,
            $skipped,
            $failed,
        ));

        return self::SUCCESS;
    }
}
