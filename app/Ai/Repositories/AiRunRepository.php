<?php

declare(strict_types=1);

namespace App\Ai\Repositories;

use App\Ai\Contracts\AiRunRepositoryInterface;
use App\Ai\Enums\AiFeature;
use App\Models\AiRun;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every ai_runs query. Aggregates are computed in SQL rather than by
 * hydrating rows: the budget guard runs before EVERY execution, so it
 * has to stay a single indexed sum no matter how large the table gets.
 */
final class AiRunRepository implements AiRunRepositoryInterface
{
    public function create(array $attributes): AiRun
    {
        return AiRun::query()->create($attributes);
    }

    public function update(AiRun $run, array $attributes): AiRun
    {
        $run->fill($attributes)->save();

        return $run;
    }

    public function estimatedCostSince(CarbonInterface $since, ?AiFeature $feature = null): float
    {
        return (float) $this->since($since, $feature)->sum('estimated_cost');
    }

    public function countSince(CarbonInterface $since, ?AiFeature $feature = null): int
    {
        return $this->since($since, $feature)->count();
    }

    public function usageByFeatureSince(CarbonInterface $since): array
    {
        return AiRun::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('feature_key, COUNT(*) as runs, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, SUM(estimated_cost) as cost')
            ->groupBy('feature_key')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (string) $row->getRawOriginal('feature_key') => [
                    'runs' => (int) $row->runs,
                    'input_tokens' => (int) $row->input_tokens,
                    'output_tokens' => (int) $row->output_tokens,
                    'cost' => (float) $row->cost,
                ],
            ])
            ->all();
    }

    /** @return Builder<AiRun> */
    private function since(CarbonInterface $since, ?AiFeature $feature): Builder
    {
        return AiRun::query()
            ->where('created_at', '>=', $since)
            ->when($feature !== null, fn (Builder $q): Builder => $q->where('feature_key', $feature->value));
    }
}
