<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

/**
 * Token accounting for one provider call. Always present on a result,
 * including a failed one — a provider can bill for a request whose
 * response we then reject, so "no usable output" must never be read as
 * "no cost".
 */
final readonly class AiUsage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        /** The provider's own request identifier, for support escalation. */
        public ?string $providerRequestId = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
