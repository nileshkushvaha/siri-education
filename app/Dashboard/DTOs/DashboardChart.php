<?php

declare(strict_types=1);

namespace App\Dashboard\DTOs;

/**
 * A dashboard chart's data, already shaped for Chart.js via Filament's
 * `ChartWidget`. The composition service produces this; the widget only
 * renders it. That keeps chart series on the same permission-checked,
 * cached path as every other dashboard figure instead of each widget
 * re-querying a report service on its own.
 */
final readonly class DashboardChart
{
    /**
     * @param  list<string>  $labels
     * @param  list<array{label: string, data: list<int|float>, color: string, segmentUrl?: string|null}>  $datasets
     * @param  list<array{label: string, value: int, percentage: float|null, color: string, url: string|null}>  $segments
     *                                                                                                                     Per-segment drill-down metadata for stacked/grouped charts, so a
     *                                                                                                                     legend entry and its destination stay in one place.
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $subtitle,
        public array $labels,
        public array $datasets,
        public array $segments = [],
        public ?string $url = null,
        public ?string $emptyMessage = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'labels' => $this->labels,
            'datasets' => $this->datasets,
            'segments' => $this->segments,
            'url' => $this->url,
            'empty_message' => $this->emptyMessage,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) $data['key'],
            title: (string) $data['title'],
            subtitle: (string) $data['subtitle'],
            labels: $data['labels'],
            datasets: $data['datasets'],
            segments: $data['segments'],
            url: $data['url'] === null ? null : (string) $data['url'],
            emptyMessage: $data['empty_message'] === null ? null : (string) $data['empty_message'],
        );
    }

    public function isEmpty(): bool
    {
        foreach ($this->datasets as $dataset) {
            foreach ($dataset['data'] as $value) {
                if ((float) $value !== 0.0) {
                    return false;
                }
            }
        }

        return true;
    }
}
