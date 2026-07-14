<?php

declare(strict_types=1);

namespace App\Quality\DTOs;

use Carbon\CarbonImmutable;

/**
 * A bounded, UTC-normalized date range for trend queries. `make()` is
 * the only public constructor path that matters for safety: a custom
 * range wider than `$maxDays` is silently clamped (bounded, not
 * rejected with an error) to the most recent `$maxDays` days ending at
 * the requested end — a dashboard should always render *something*
 * useful rather than fail outright on operator error.
 */
final readonly class TrendDateRange
{
    private const int DEFAULT_MAX_DAYS = 90;

    private function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    public static function make(CarbonImmutable $start, CarbonImmutable $end, int $maxDays = self::DEFAULT_MAX_DAYS): self
    {
        $start = $start->utc()->startOfDay();
        $end = $end->utc()->endOfDay();

        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        // $maxDays is inclusive of both endpoints (e.g. maxDays=90 means
        // "today plus the 89 days before it" = 90 calendar days total).
        $earliestAllowed = $end->subDays($maxDays - 1)->startOfDay();

        if ($start->lessThan($earliestAllowed)) {
            $start = $earliestAllowed;
        }

        return new self($start, $end);
    }

    public static function lastDays(int $days): self
    {
        return self::make(CarbonImmutable::now()->subDays($days - 1), CarbonImmutable::now());
    }

    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }
}
