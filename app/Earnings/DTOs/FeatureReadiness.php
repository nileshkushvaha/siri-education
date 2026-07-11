<?php

declare(strict_types=1);

namespace App\Earnings\DTOs;

use Carbon\CarbonImmutable;

/**
 * The typed result of a financial feature-activation preflight. When
 * not ready, the blocking codes/messages name every failed rule and the
 * affected instructors — the Filament page displays this DTO instead of
 * duplicating any rule.
 */
final readonly class FeatureReadiness
{
    /**
     * @param  list<string>  $blockingCodes
     * @param  list<string>  $blockingMessages
     * @param  list<string>  $affectedSubjects  instructor names/references per failure
     */
    public function __construct(
        public string $feature,
        public bool $isReady,
        public array $blockingCodes,
        public array $blockingMessages,
        public array $affectedSubjects,
        public bool $currentlyEnabled,
        public CarbonImmutable $evaluatedAt,
    ) {}

    /** UI-safe one-liner combining every blocking rule. */
    public function summary(): string
    {
        if ($this->isReady) {
            return 'All activation checks passed.';
        }

        return implode(' • ', $this->blockingMessages);
    }
}
