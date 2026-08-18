<?php

declare(strict_types=1);

namespace App\Ai\Services;

use App\Settings\AiSettings;

/**
 * Turns token counts into an estimated cost using the admin-maintained
 * price table.
 *
 * ESTIMATE, never invoice. Providers bill on their own terms (cached
 * input tiers, batch discounts, rounding), so this number exists to
 * make spend visible and to power the budget guard — not to reconcile
 * against a statement. An unpriced model returns 0.0 rather than a
 * guess, and the settings page shows which models are unpriced so the
 * gap is visible instead of silently understating spend.
 */
final class AiCostEstimator
{
    public function __construct(
        private readonly AiSettings $settings,
    ) {}

    public function estimate(string $model, int $inputTokens, int $outputTokens): float
    {
        [$inputPrice, $outputPrice] = $this->prices($model);

        return round(($inputPrice * $inputTokens / 1_000_000) + ($outputPrice * $outputTokens / 1_000_000), 6);
    }

    public function isPriced(string $model): bool
    {
        return filled($this->settings->model_pricing[$model] ?? null);
    }

    /**
     * Stored as "input/output" per million tokens. A malformed or
     * missing entry prices at zero rather than throwing: a bad price
     * string must never be able to stop an AI feature, only to
     * understate a cost estimate, which isPriced() makes visible.
     *
     * @return array{0: float, 1: float}
     */
    private function prices(string $model): array
    {
        $raw = $this->settings->model_pricing[$model] ?? null;

        if (! is_string($raw) || $raw === '') {
            return [0.0, 0.0];
        }

        [$input, $output] = array_pad(explode('/', $raw, 2), 2, '0');

        return [(float) $input, (float) $output];
    }

    public function currency(): string
    {
        return $this->settings->cost_currency;
    }
}
