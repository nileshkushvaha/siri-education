<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Wallet\Contracts\LessonWalletRefundServiceInterface;
use Illuminate\Console\Command;

/**
 * Credits approved lesson-outcome wallet refunds through
 * the wallet domain. Idempotent (ledger idempotency keys), batched,
 * per-record failure isolated; a no-op while
 * instructor_earnings.lesson_refund_execution_enabled is off.
 */
class ProcessLessonRefunds extends Command
{
    protected $signature = 'lessons:process-refunds';

    protected $description = 'Execute approved lesson-outcome wallet refund dispositions';

    public function handle(LessonWalletRefundServiceInterface $refunds): int
    {
        $credited = $refunds->processReady();

        $this->info(sprintf('Credited %d wallet refund(s).', $credited));

        return self::SUCCESS;
    }
}
