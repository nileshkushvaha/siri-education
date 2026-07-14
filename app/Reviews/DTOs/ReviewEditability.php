<?php

declare(strict_types=1);

namespace App\Reviews\DTOs;

use Carbon\CarbonImmutable;

/**
 * Whether one review may currently be edited by its owner, plus the
 * UI-safe reason when it may not and the deadline while it may. The
 * reason is always neutral — never a moderation note, report detail,
 * or reporter identity.
 */
final readonly class ReviewEditability
{
    public function __construct(
        public bool $editable,
        public ?string $reason = null,
        public ?CarbonImmutable $deadline = null,
    ) {}

    public static function allowed(CarbonImmutable $deadline): self
    {
        return new self(editable: true, deadline: $deadline);
    }

    public static function denied(string $reason): self
    {
        return new self(editable: false, reason: $reason);
    }
}
