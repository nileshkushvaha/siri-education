<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Models\InstructorCompensationException;
use Illuminate\Console\Command;

/**
 * Recovery sweep for compensation-blocked lessons. Re-invokes the
 * normal (idempotent, kill-switch-gated) earning creation for every
 * open retry-eligible exception; resolution always re-derives the
 * agreement in force at the lesson's SCHEDULED start — a retry running
 * later can never pick up a newer rate merely because time passed.
 * Permanently ineligible exceptions are never retried; still-blocked
 * lessons stay in the admin queue with an updated attempt count.
 */
final class RetryBlockedLessons extends Command
{
    protected $signature = 'instructor-earnings:retry-blocked-lessons';

    protected $description = 'Retry earning creation for compensation-blocked completed lessons.';

    public function handle(InstructorEarningServiceInterface $earnings): int
    {
        $recovered = 0;
        $attempted = 0;

        InstructorCompensationException::query()
            ->retryable()
            ->orderBy('first_failed_at')
            ->with('lesson')
            ->cursor()
            ->each(function (InstructorCompensationException $exception) use ($earnings, &$recovered, &$attempted): void {
                if ($exception->lesson === null) {
                    return;
                }

                $attempted++;

                // Idempotent: an existing earning is returned untouched,
                // a still-blocked lesson re-records its exception.
                if ($earnings->createFromLesson($exception->lesson) !== null) {
                    $recovered++;
                }
            });

        $this->info("Retried {$attempted} blocked lesson(s); recovered {$recovered}.");

        return self::SUCCESS;
    }
}
