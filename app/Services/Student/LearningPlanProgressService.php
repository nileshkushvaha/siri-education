<?php

declare(strict_types=1);

namespace App\Services\Student;

use App\Models\StudentLearningPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The single write boundary for `student_learning_plans.progress_percent`
 * (SRS §6.17 item 5). Every evidence mutation that can change
 * a plan's progress — milestone create/complete, homework assign/
 * relink/grade — funnels through recalculate() instead of writing
 * progress_percent directly, so the calculation, the row lock, the
 * unchanged-value skip, and the writable-status guard all hold
 * globally in one place.
 */
final class LearningPlanProgressService
{
    public function __construct(
        private readonly LearningPlanProgressCalculator $calculator,
    ) {}

    /**
     * Safe no-op when the plan is missing, soft-deleted, or already
     * Completed/Archived — SRS §6.19: "Completed Learning Plans shall
     * not accept new progress updates." $actor is null only for the
     * system-run backfill command, which leaves updated_by untouched
     * rather than attributing the change to a synthetic user.
     */
    public function recalculate(?StudentLearningPlan $plan, ?User $actor): void
    {
        if ($plan === null) {
            return;
        }

        DB::transaction(function () use ($plan, $actor): void {
            $locked = StudentLearningPlan::query()
                ->whereKey($plan->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! $locked->status->isWritable()) {
                return;
            }

            $percent = $this->calculator->calculate($locked);

            if ($locked->progress_percent === $percent) {
                return;
            }

            $locked->forceFill([
                'progress_percent' => $percent,
                ...($actor !== null ? ['updated_by' => $actor->id] : []),
            ])->save();
        });
    }
}
