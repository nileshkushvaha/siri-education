<?php

declare(strict_types=1);

namespace App\Reviews\DTOs;

use App\Reviews\Enums\ReviewContentFlag;

/**
 * The result of sanitizing one piece of submitted review text.
 * `content` is always safe to store/render — HTML/scripts are
 * stripped and anything contact-shaped is redacted. `flags` records
 * only WHICH categories tripped, never the matched text itself.
 */
final readonly class SanitizedReviewContent
{
    /** @param list<ReviewContentFlag> $flags */
    public function __construct(
        public ?string $content,
        public array $flags,
    ) {}

    public function isClean(): bool
    {
        return $this->flags === [];
    }
}
