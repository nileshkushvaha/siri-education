<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Earnings\Contracts\InstructorPeriodicCompensationServiceInterface;
use Illuminate\Console\Command;

/**
 * Accrues closed daily/weekly/monthly compensation periods. Idempotent
 * (DB-unique per agreement + period) and gated inside the service by
 * earnings_enabled — the command cannot bypass the kill switch. Runs
 * hourly so day boundaries in every agreement timezone are picked up
 * promptly. No money moves; earnings enter the normal hold → release
 * lifecycle.
 */
final class AccruePeriodicCompensation extends Command
{
    protected $signature = 'instructor-earnings:accrue-periodic-compensation';

    protected $description = 'Accrue closed daily/weekly/monthly instructor compensation periods into earnings.';

    public function handle(InstructorPeriodicCompensationServiceInterface $service): int
    {
        $accrued = $service->accrueClosedPeriods();

        $this->info("Accrued {$accrued} period(s).");

        return self::SUCCESS;
    }
}
