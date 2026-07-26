<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

/**
 * The complete AND-chain result: global flag, country flag,
 * meeting-level flag, provider capability, confirmed
 * booking, both participants' consent, both participants' lifecycle
 * standing. $reason is a stable machine-readable code (never a
 * user-facing sentence with personal detail) so tests can assert
 * exactly which gate failed.
 */
final readonly class RecordingEligibilityResult
{
    private function __construct(
        public bool $eligible,
        public ?string $reason,
    ) {}

    public static function eligible(): self
    {
        return new self(true, null);
    }

    public static function ineligible(string $reason): self
    {
        return new self(false, $reason);
    }
}
