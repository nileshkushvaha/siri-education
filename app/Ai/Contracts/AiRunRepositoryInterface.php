<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\Enums\AiFeature;
use App\Models\AiRun;
use Carbon\CarbonInterface;

/**
 * All ai_runs query composition. Services never write Eloquent queries
 * directly (docs/decisions.md).
 */
interface AiRunRepositoryInterface
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): AiRun;

    /** @param array<string, mixed> $attributes */
    public function update(AiRun $run, array $attributes): AiRun;

    /** Total estimated cost of runs created since $since, in the platform's AI cost currency. */
    public function estimatedCostSince(CarbonInterface $since, ?AiFeature $feature = null): float;

    public function countSince(CarbonInterface $since, ?AiFeature $feature = null): int;

    /** @return array<string, array{runs: int, input_tokens: int, output_tokens: int, cost: float}> keyed by feature_key */
    public function usageByFeatureSince(CarbonInterface $since): array;
}
