<?php

declare(strict_types=1);

namespace App\Earnings\Jobs;

use App\Earnings\Contracts\InstructorPayoutExecutionServiceInterface;
use App\Models\InstructorPayoutAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Carries only the attempt ID — never decrypted destination data — so
 * the queue payload/`failed_jobs` row never contains bank details.
 * `$tries = 1`: a Laravel-level job retry would risk a second provider
 * call on infrastructure failure (worker crash, DB blip); the payout's
 * OWN retry policy (bounded, provider-outcome-aware) is entirely owned
 * by InstructorPayoutExecutionService and re-dispatches this job itself
 * with a delay when — and only when — a retry is provably safe. A
 * queue infrastructure failure is therefore never treated as a payout
 * failure: it surfaces as a failed job for operations to see, without
 * touching any reservation or withdrawal status.
 */
final class InitiateInstructorPayout implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $attemptId,
    ) {
        $this->onQueue('payouts');
    }

    public function uniqueId(): string
    {
        return $this->attemptId;
    }

    public function handle(InstructorPayoutExecutionServiceInterface $service): void
    {
        $attempt = InstructorPayoutAttempt::query()->find($this->attemptId);

        if ($attempt === null) {
            Log::warning('InitiateInstructorPayout: attempt not found — nothing to execute.', ['attempt_id' => $this->attemptId]);

            return;
        }

        // The service itself re-dispatches this job (with a delay) when,
        // and only when, a bounded automatic retry is safe — this
        // method never re-dispatches on its own to avoid a double queue.
        $service->execute($attempt);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('InitiateInstructorPayout: job failed — no financial state changed.', [
            'attempt_id' => $this->attemptId,
            'error' => $exception->getMessage(),
        ]);
    }
}
