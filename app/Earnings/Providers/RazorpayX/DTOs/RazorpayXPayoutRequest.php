<?php

declare(strict_types=1);

namespace App\Earnings\Providers\RazorpayX\DTOs;

/**
 * Everything Create Payout needs, and nothing else — no student data,
 * no student price, no platform margin, no admin notes beyond the
 * safe platform reference. `idempotencyKey` is always the payout
 * attempt's own key (§4 of the phase spec) — a retry of the same
 * logical attempt reuses it, never mints a new one.
 */
final readonly class RazorpayXPayoutRequest
{
    public function __construct(
        public string $accountNumber,
        public string $fundAccountId,
        public int $amountMinor,
        public string $mode,
        public string $purpose,
        public string $referenceId,
        public string $narration,
        public string $idempotencyKey,
        public bool $queueIfLowBalance,
    ) {}
}
