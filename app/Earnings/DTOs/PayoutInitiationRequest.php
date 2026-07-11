<?php

declare(strict_types=1);

namespace App\Earnings\DTOs;

/**
 * Everything a provider adapter needs to initiate a payout, and nothing
 * else — no student price, no platform margin, no raw Eloquent models,
 * no admin notes, no application secrets. `destinationSnapshot` is the
 * decrypted-for-this-call-only payload built from the withdrawal's
 * immutable encrypted snapshot (never the live, mutable payout method).
 * `scenario` is a fake-provider-only testing hook (App\Earnings\Providers\Fake\FakeInstructorPayoutProvider):
 * always null in any real execution path; a real adapter must ignore it.
 */
final readonly class PayoutInitiationRequest
{
    /** @param array<string, mixed> $destinationSnapshot */
    public function __construct(
        public string $attemptReference,
        public string $withdrawalReference,
        public int $amountMinor,
        public string $currencyCode,
        public string $idempotencyKey,
        public array $destinationSnapshot,
        public string $purpose,
        public ?string $safeNotes = null,
        public ?string $scenario = null,
    ) {}
}
