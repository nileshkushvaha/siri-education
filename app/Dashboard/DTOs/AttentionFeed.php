<?php

declare(strict_types=1);

namespace App\Dashboard\DTOs;

use App\Reporting\Enums\ReportDataFreshness;
use Carbon\CarbonImmutable;

/**
 * The Needs Attention section. Held separately from
 * {@see DashboardData} because it has a fundamentally different
 * freshness contract: these are urgent current-state counts, so they
 * use a much shorter TTL than the period-scoped composition and carry
 * their own "as of" timestamp. Presenting an operational alert count
 * with a five-minute-old period timestamp would understate how current
 * it is.
 */
final readonly class AttentionFeed
{
    public const int MAX_VISIBLE = 6;

    /**
     * @param  list<AttentionItem>  $items  Already severity-sorted and render-filtered.
     */
    public function __construct(
        public array $items,
        public CarbonImmutable $generatedAt,
        public string $reportingTimezone,
        public ReportDataFreshness $freshness,
        public ?string $overflowUrl = null,
    ) {}

    /** @return list<AttentionItem> */
    public function visible(): array
    {
        return array_slice($this->items, 0, self::MAX_VISIBLE);
    }

    public function overflowCount(): int
    {
        return max(0, count($this->items) - self::MAX_VISIBLE);
    }

    /** @return list<AttentionItem> */
    public function overflow(): array
    {
        return array_slice($this->items, self::MAX_VISIBLE);
    }

    public function isClear(): bool
    {
        foreach ($this->items as $item) {
            if ($item->count > 0) {
                return false;
            }
        }

        return true;
    }

    public function generatedAtLabel(): string
    {
        return $this->generatedAt->setTimezone($this->reportingTimezone)->toDayDateTimeString();
    }
}
