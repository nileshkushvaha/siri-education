<?php

declare(strict_types=1);

namespace App\Dashboard\DTOs;

/**
 * One primary KPI card. The dashboard is never a calculation owner —
 * every `$value` here is a figure already produced by an
 * `App\Reporting` service, re-rendered rather than recomputed, and
 * `$definition` quotes that owner's own metric definition so two
 * surfaces can never describe the same named KPI two different ways.
 *
 * `$isUnavailable` exists because several authoritative metrics use
 * `ZeroDenominatorPolicy::ReturnNull` — for those, "no valid
 * denominator" must render as an em dash, never as `0%`.
 */
final readonly class KpiCard
{
    /**
     * @param  string  $value  Pre-formatted display string ("1,204", "38.4%", "—").
     * @param  string  $contextLabel  Period or as-of framing, e.g. "Last 30 days" / "As of now".
     * @param  string  $definition  Short authoritative definition, surfaced as a tooltip/supporting line.
     * @param  string|null  $url  Owning report. Null only when the viewer may see the figure but not its report.
     * @param  list<int|float>|null  $sparkline  Only when an authoritative trend series already exists — never synthesised.
     * @param  bool  $isUnavailable  True when the owning metric returned null (no valid denominator / no data).
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $value,
        public string $contextLabel,
        public string $definition,
        public string $icon,
        public ?string $url = null,
        public ?array $sparkline = null,
        public bool $isUnavailable = false,
        public ?string $unavailableReason = null,
    ) {}

    /** An em-dash card carries no click-through promise beyond its owning report. */
    public function hasSparkline(): bool
    {
        return $this->sparkline !== null && count($this->sparkline) > 1;
    }

    /**
     * Primitive form for the cache. Objects are never stored: a
     * serialized instance written before a deploy rehydrates as
     * `__PHP_Incomplete_Class` once the class changes, which a
     * persistent cache store (this application uses `database`) keeps
     * serving until the TTL expires. Round-tripping plain scalars makes
     * a stale entry harmless.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'value' => $this->value,
            'context_label' => $this->contextLabel,
            'definition' => $this->definition,
            'icon' => $this->icon,
            'url' => $this->url,
            'sparkline' => $this->sparkline,
            'is_unavailable' => $this->isUnavailable,
            'unavailable_reason' => $this->unavailableReason,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) $data['key'],
            label: (string) $data['label'],
            value: (string) $data['value'],
            contextLabel: (string) $data['context_label'],
            definition: (string) $data['definition'],
            icon: (string) $data['icon'],
            url: $data['url'] === null ? null : (string) $data['url'],
            sparkline: $data['sparkline'],
            isUnavailable: (bool) $data['is_unavailable'],
            unavailableReason: $data['unavailable_reason'] === null ? null : (string) $data['unavailable_reason'],
        );
    }
}
