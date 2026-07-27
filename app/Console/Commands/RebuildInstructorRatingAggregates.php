<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Reviews\Contracts\InstructorRatingAggregateServiceInterface;
use Illuminate\Console\Command;

/**
 * Repair/reconciliation tool, not the primary update path
 * (that's the event-driven ReconcileReviewContributionAction). Fully
 * recomputes instructor rating aggregates from current eligible
 * published reviews. Idempotent, deterministic, per-instructor failure
 * isolated. Deliberately NOT scheduled — the incremental path already
 * keeps aggregates correct under normal operation; run this manually
 * after suspected drift or data repair.
 */
class RebuildInstructorRatingAggregates extends Command
{
    protected $signature = 'reviews:rebuild-aggregates {--instructor= : Rebuild only this instructor id}';

    protected $description = 'Rebuild instructor rating aggregates from current eligible published reviews';

    public function handle(InstructorRatingAggregateServiceInterface $ratings): int
    {
        $instructorId = $this->option('instructor');

        if ($instructorId !== null) {
            $ratings->rebuildForInstructor((int) $instructorId);
            $this->info(sprintf('Rebuilt rating aggregate for instructor %d.', $instructorId));

            return self::SUCCESS;
        }

        $rebuilt = $ratings->rebuildAll();

        $this->info(sprintf('Rebuilt %d instructor rating aggregate(s).', $rebuilt));

        return self::SUCCESS;
    }
}
