<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Ai;

/**
 * The whole AI system for one period: per-feature evaluation, prompt
 * comparison, and the spend position against the configured ceilings.
 */
final readonly class AiEvaluationOverviewData
{
    /**
     * @param  list<AiFeatureEvaluationRow>  $features
     * @param  list<AiPromptVersionRow>  $promptVersions
     */
    public function __construct(
        public string $periodLabel,
        public array $features,
        public array $promptVersions,
        public float $totalCost,
        public string $costCurrency,
        public float $spentToday,
        public float $spentThisMonth,
        public ?float $dailyLimit,
        public ?float $monthlyLimit,
        public bool $aiEnabled,
        /** @var list<string> labels of the capabilities currently switched on */
        public array $enabledCapabilities,
    ) {}

    public function totalRuns(): int
    {
        return array_sum(array_map(fn (AiFeatureEvaluationRow $row): int => $row->runs, $this->features));
    }

    /** Null when no limit is configured — never 0, which would read as "no headroom". */
    public function dailyBudgetUsedRatio(): ?float
    {
        return $this->dailyLimit === null || $this->dailyLimit <= 0.0
            ? null
            : round($this->spentToday / $this->dailyLimit, 4);
    }

    public function monthlyBudgetUsedRatio(): ?float
    {
        return $this->monthlyLimit === null || $this->monthlyLimit <= 0.0
            ? null
            : round($this->spentThisMonth / $this->monthlyLimit, 4);
    }
}
