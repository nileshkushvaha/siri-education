<?php

declare(strict_types=1);

namespace App\Dashboard\DTOs;

/**
 * A permission-gated group of at most three compact metrics plus one
 * "Open report" action. A summary whose viewer lacks the underlying
 * permission is never constructed — the composition service omits it
 * before querying, so the grid simply closes the gap rather than
 * rendering a "Restricted" placeholder.
 */
final readonly class DomainSummary
{
    /**
     * @param  list<SummaryMetric>  $metrics  Capped at three by the composition service.
     * @param  string|null  $notice  An honest state note (e.g. provider not activated) shown above the metrics.
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $icon,
        public array $metrics,
        public string $reportLabel,
        public ?string $reportUrl,
        public ?string $notice = null,
    ) {}

    public function hasMetrics(): bool
    {
        return $this->metrics !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'icon' => $this->icon,
            'metrics' => array_map(static fn (SummaryMetric $m): array => $m->toArray(), $this->metrics),
            'report_label' => $this->reportLabel,
            'report_url' => $this->reportUrl,
            'notice' => $this->notice,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) $data['key'],
            title: (string) $data['title'],
            icon: (string) $data['icon'],
            metrics: array_map(SummaryMetric::fromArray(...), $data['metrics']),
            reportLabel: (string) $data['report_label'],
            reportUrl: $data['report_url'] === null ? null : (string) $data['report_url'],
            notice: $data['notice'] === null ? null : (string) $data['notice'],
        );
    }
}
