<?php

declare(strict_types=1);

namespace App\Dashboard\DTOs;

/**
 * One compact figure inside a {@see DomainSummary}. Deliberately
 * poorer than {@see KpiCard}: a domain summary is a three-line teaser
 * that hands off to the owning report, not a second place to read a
 * full breakdown.
 */
final readonly class SummaryMetric
{
    public function __construct(
        public string $label,
        public string $value,
        public ?string $hint = null,
        public bool $isUnavailable = false,
    ) {}

    /** A metric whose owning calculation returned null renders an em dash, never a zero. */
    public static function unavailable(string $label, string $reason): self
    {
        return new self(label: $label, value: '—', hint: $reason, isUnavailable: true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'value' => $this->value,
            'hint' => $this->hint,
            'is_unavailable' => $this->isUnavailable,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            label: (string) $data['label'],
            value: (string) $data['value'],
            hint: $data['hint'] === null ? null : (string) $data['hint'],
            isUnavailable: (bool) $data['is_unavailable'],
        );
    }
}
