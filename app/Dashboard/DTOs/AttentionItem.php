<?php

declare(strict_types=1);

namespace App\Dashboard\DTOs;

use App\Dashboard\Enums\AttentionSeverity;
use App\Dashboard\Services\AttentionFeedService;

/**
 * One Needs Attention card. Built exclusively by
 * {@see AttentionFeedService}, which only ever
 * constructs a card the viewer is already authorised to see — an
 * unauthorised category is never instantiated, so its count query
 * never runs.
 *
 * Attention items are CURRENT-STATE figures, never scoped by the
 * dashboard's period selector. Where an authoritative calculation has
 * no current-state form, the feed pins a fixed window and says so in
 * `$asOfLabel`; the selector still cannot move the number.
 */
final readonly class AttentionItem
{
    /**
     * @param  string  $key  Stable identifier, used for ordering assertions and tests.
     * @param  int  $count  Current-state count. Zero is meaningful only when `$isIntegritySignal`.
     * @param  string  $explanation  One short sentence: what this is and why it matters.
     * @param  string  $url  Real, existing destination. Never a fabricated route.
     * @param  int  $tieBreaker  Secondary sort weight inside one severity (lower first).
     * @param  bool  $isIntegritySignal  A hard-zero invariant that renders green at zero rather than disappearing.
     */
    public function __construct(
        public string $key,
        public string $label,
        public int $count,
        public AttentionSeverity $severity,
        public string $explanation,
        public string $icon,
        public string $url,
        public string $destinationLabel,
        public int $tieBreaker = 50,
        public bool $isIntegritySignal = false,
        public string $asOfLabel = 'As of now',
    ) {}

    /**
     * Zero-count cards are hidden unless they are an integrity signal,
     * where "0" is the whole point (a wallet/ledger mismatch of zero is
     * a success worth confirming, not an absence worth hiding).
     */
    public function shouldRender(): bool
    {
        return $this->count > 0 || $this->isIntegritySignal;
    }

    /** An integrity signal at zero renders as Healthy, not as its configured severity. */
    public function effectiveSeverity(): AttentionSeverity
    {
        if ($this->isIntegritySignal && $this->count === 0) {
            return AttentionSeverity::Success;
        }

        return $this->severity;
    }

    /** Composite sort key: severity first, then the category tie-breaker, then key for stability. */
    public function sortKey(): string
    {
        return sprintf('%d-%03d-%s', $this->effectiveSeverity()->rank(), $this->tieBreaker, $this->key);
    }

    /**
     * Primitive representation for the cache. Objects are deliberately
     * NOT stored in the cache: a readonly DTO's shape is part of the
     * deploy, and a serialized instance from a previous release would
     * fail to hydrate against a changed constructor. Round-tripping
     * plain scalars makes a stale entry harmless.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'count' => $this->count,
            'severity' => $this->severity->value,
            'explanation' => $this->explanation,
            'icon' => $this->icon,
            'url' => $this->url,
            'destination_label' => $this->destinationLabel,
            'tie_breaker' => $this->tieBreaker,
            'is_integrity_signal' => $this->isIntegritySignal,
            'as_of_label' => $this->asOfLabel,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) $data['key'],
            label: (string) $data['label'],
            count: (int) $data['count'],
            severity: AttentionSeverity::from((string) $data['severity']),
            explanation: (string) $data['explanation'],
            icon: (string) $data['icon'],
            url: (string) $data['url'],
            destinationLabel: (string) $data['destination_label'],
            tieBreaker: (int) $data['tie_breaker'],
            isIntegritySignal: (bool) $data['is_integrity_signal'],
            asOfLabel: (string) $data['as_of_label'],
        );
    }
}
