<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\StudentLearningPlan;
use App\Services\Student\LearningPlanProgressCalculator;
use App\Services\Student\LearningPlanProgressService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Bounded, idempotent backfill for GAP-023: historical plans still
 * carry the pre-fix milestone-only percentage. The comparison always
 * runs through LearningPlanProgressCalculator (the single formula) and
 * the actual write always runs through LearningPlanProgressService
 * (the single write boundary), so a plan that is already correct, or
 * already Completed/Archived — which must never accept a new progress
 * update — is reported as unchanged/skipped, never touched. Never
 * changes a plan's lifecycle status; never runs automatically.
 */
final class RecalculateLearningPlanProgress extends Command
{
    protected $signature = 'learning-plans:recalculate-progress
        {--plan= : Recalculate a single plan by id}
        {--dry-run : Report what would change without writing anything}
        {--chunk=200 : Number of plans read per chunk}';

    protected $description = 'Recalculate stored learning-plan progress percentages using the corrected composite formula (GAP-023).';

    public function handle(LearningPlanProgressCalculator $calculator, LearningPlanProgressService $progress): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $planId = $this->option('plan');

        $changed = 0;
        $unchanged = 0;
        $skipped = 0;
        $failed = 0;

        $query = StudentLearningPlan::query()->when(
            $planId !== null,
            fn ($q) => $q->whereKey($planId),
        );

        $query->orderBy('id')->chunkById($chunkSize, function ($plans) use ($calculator, $progress, $dryRun, &$changed, &$unchanged, &$skipped, &$failed): void {
            foreach ($plans as $plan) {
                try {
                    if (! $plan->status->isWritable()) {
                        $skipped++;

                        continue;
                    }

                    $computed = $calculator->calculate($plan);

                    if ($plan->progress_percent === $computed) {
                        $unchanged++;

                        continue;
                    }

                    $changed++;

                    if (! $dryRun) {
                        $progress->recalculate($plan, null);
                    }
                } catch (Throwable $e) {
                    $failed++;
                    $this->error(sprintf('Plan %d: %s', $plan->id, $e->getMessage()));
                }
            }
        });

        $this->components->twoColumnDetail('Mode', $dryRun ? 'dry-run (no writes)' : 'live');
        $this->components->twoColumnDetail('Changed', (string) $changed);
        $this->components->twoColumnDetail('Unchanged', (string) $unchanged);
        $this->components->twoColumnDetail('Skipped (not writable)', (string) $skipped);
        $this->components->twoColumnDetail('Failed', (string) $failed);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
