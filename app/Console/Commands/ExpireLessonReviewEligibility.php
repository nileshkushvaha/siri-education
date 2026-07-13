<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Reviews\Contracts\ReviewEligibilityServiceInterface;
use Illuminate\Console\Command;

/**
 * Phase 17H: expires open review-eligibility windows whose deadline
 * has passed. Idempotent, batched, per-record failure isolated — never
 * touches Used or Revoked records.
 */
class ExpireLessonReviewEligibility extends Command
{
    protected $signature = 'reviews:expire-eligibility';

    protected $description = 'Expire overdue open lesson review-eligibility windows';

    public function handle(ReviewEligibilityServiceInterface $eligibility): int
    {
        $expired = $eligibility->expireDue();

        $this->info(sprintf('Expired %d review eligibility window(s).', $expired));

        return self::SUCCESS;
    }
}
