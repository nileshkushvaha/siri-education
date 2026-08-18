<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Ai\Contracts\AiRunRepositoryInterface;
use App\Ai\Enums\AiFailureCode;
use App\Settings\AiSettings;
use Illuminate\Support\Carbon;

/**
 * Platform-wide daily and monthly spend ceilings, checked before every
 * execution.
 *
 * Deliberately coarse in P0: two ceilings over ESTIMATED spend, no
 * per-feature or per-user limits yet. The finer limits the roadmap
 * calls for need no new architecture — ai_runs already carries
 * feature_key and requested_by, so a per-feature or per-user ceiling is
 * one more settings field and one more repository sum, which is exactly
 * the point of recording them from day one.
 *
 * Estimated spend can lag reality, so this is a brake, not a hard
 * spend cap; treat the provider's own budget controls as the last line.
 * A null limit means unlimited; 0.0 means "block everything", a
 * meaningful emergency setting distinct from unlimited.
 */
final class AiBudgetGuard
{
    public function __construct(
        private readonly AiSettings $settings,
        private readonly AiRunRepositoryInterface $runs,
    ) {}

    /** Null means "within budget"; otherwise the code recorded on the blocked run. */
    public function blockReason(): ?AiFailureCode
    {
        $daily = $this->settings->daily_cost_limit;

        if ($daily !== null && $this->spentToday() >= $daily) {
            return AiFailureCode::BudgetExceeded;
        }

        $monthly = $this->settings->monthly_cost_limit;

        if ($monthly !== null && $this->spentThisMonth() >= $monthly) {
            return AiFailureCode::BudgetExceeded;
        }

        return null;
    }

    public function spentToday(): float
    {
        return $this->runs->estimatedCostSince(Carbon::now()->startOfDay());
    }

    public function spentThisMonth(): float
    {
        return $this->runs->estimatedCostSince(Carbon::now()->startOfMonth());
    }
}
