<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Models\InstructorWithdrawalRequest;

/**
 * Generates the keyed fingerprint over a payout execution's immutable
 * inputs (§15): withdrawal ID, execution sequence, amount, currency,
 * snapshot version, destination fingerprint, provider, purpose. Two
 * calls with identical inputs always produce the same fingerprint;
 * changing any input changes it — this is what lets
 * InstructorPayoutExecutionService detect "same idempotency key, but
 * the request content changed" and refuse instead of replaying.
 */
interface PayoutRequestFingerprintServiceInterface
{
    /** @param array<string, mixed> $destinationSnapshot */
    public function generate(
        InstructorWithdrawalRequest $withdrawal,
        int $executionSequence,
        string $provider,
        array $destinationSnapshot,
        string $purpose,
    ): string;
}
