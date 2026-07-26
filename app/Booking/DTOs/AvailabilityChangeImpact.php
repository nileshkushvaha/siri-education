<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use Carbon\CarbonImmutable;

/**
 * SRS §10.24: the result of analyzing a proposed availability-reducing
 * mutation against the instructor's future
 * confirmed bookings. Produced by AvailabilityChangeImpactService and
 * never mutates anything itself.
 *
 * Deliberately carries ONLY scheduling data safe to show the acting
 * instructor/admin: booking references and lesson times in the
 * instructor's timezone. Never student financial data, student private
 * profile data, or payment-provider references.
 */
final readonly class AvailabilityChangeImpact
{
    /** How many affected-lesson summaries are included at most — the full count is always in $affectedCount. */
    public const int SUMMARY_LIMIT = 10;

    public function __construct(
        public bool $requiresConfirmation,
        public int $affectedCount,
        /** @var list<string> internal booking ids — never for client display */
        public array $affectedBookingIds,
        /** @var list<array{reference: string, starts_at: string}> capped safe summaries (instructor-timezone times) */
        public array $affectedSummaries,
        public ?CarbonImmutable $earliestAffectedStartsAt,
        public ?CarbonImmutable $latestAffectedStartsAt,
        public string $mutationType,
        /** Opaque HMAC fingerprint binding this exact proposal + impact + schedule version — the confirmation token. */
        public string $fingerprint,
        public CarbonImmutable $analyzedAt,
    ) {}

    public static function none(string $mutationType): self
    {
        return new self(
            requiresConfirmation: false,
            affectedCount: 0,
            affectedBookingIds: [],
            affectedSummaries: [],
            earliestAffectedStartsAt: null,
            latestAffectedStartsAt: null,
            mutationType: $mutationType,
            fingerprint: '',
            analyzedAt: CarbonImmutable::now(),
        );
    }
}
