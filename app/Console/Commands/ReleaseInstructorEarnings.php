<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Earnings\Contracts\InstructorEarningServiceInterface;
use Illuminate\Console\Command;

/**
 * Promotes pending_hold earnings whose hold period has lapsed to
 * releasable. Idempotent; gated by InstructorEarningSettings::
 * auto_release_enabled; touches no payment/wallet/payout systems.
 */
class ReleaseInstructorEarnings extends Command
{
    protected $signature = 'instructor-earnings:release';

    protected $description = 'Release instructor earnings whose hold period has lapsed';

    public function handle(InstructorEarningServiceInterface $earnings): int
    {
        $released = $earnings->releaseDue();

        $this->info(sprintf('Released %d earning(s).', $released));

        return self::SUCCESS;
    }
}
