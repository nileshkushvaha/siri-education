<?php

declare(strict_types=1);

namespace App\Ai\Prompts;

/**
 * A prompt with its variables substituted in — i.e. the point at which
 * it may contain student content. Deliberately has no __toString(), no
 * toArray() and no jsonSerialize(): the easiest way for content to leak
 * into a log line is for the object holding it to be trivially
 * stringifiable.
 */
final readonly class RenderedPrompt
{
    public function __construct(
        public string $system,
        public string $user,
        public PromptDefinition $definition,
    ) {}
}
