<?php

declare(strict_types=1);

namespace App\Ai\Providers\OpenAi;

/**
 * A decoded OpenAI HTTP response plus the provider's request id, which
 * is the one thing worth persisting from a call: it identifies the
 * request in OpenAI's own logs without keeping any of its content here.
 */
final readonly class OpenAiApiResponse
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public array $body,
        public ?string $requestId = null,
    ) {}
}
