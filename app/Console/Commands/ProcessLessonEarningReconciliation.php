<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Earnings\Contracts\LessonEarningReconciliationServiceInterface;
use Illuminate\Console\Command;

/**
 * Executes approved instructor-earning reconciliations
 * (create / hold / restore-release / reverse) through the existing
 * earning domain. Idempotent, batched, per-record failure isolated;
 * a no-op while
 * instructor_earnings.earning_reconciliation_execution_enabled is off.
 */
class ProcessLessonEarningReconciliation extends Command
{
    protected $signature = 'lessons:process-earning-reconciliation';

    protected $description = 'Execute approved instructor earning reconciliation dispositions';

    public function handle(LessonEarningReconciliationServiceInterface $reconciliation): int
    {
        $applied = $reconciliation->processReady();

        $this->info(sprintf('Applied %d earning reconciliation(s).', $applied));

        return self::SUCCESS;
    }
}
