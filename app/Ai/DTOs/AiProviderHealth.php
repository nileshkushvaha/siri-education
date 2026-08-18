<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

/**
 * $safeMessage is shown to an admin, so it is always a fixed sentence
 * chosen by the adapter — never the provider's raw error text, which
 * can contain request echoes or key fragments.
 */
final readonly class AiProviderHealth
{
    public function __construct(
        public bool $healthy,
        public ?string $safeMessage = null,
    ) {}
}
