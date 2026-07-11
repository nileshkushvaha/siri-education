<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Earnings\Contracts\PayoutRequestFingerprintServiceInterface;
use App\Earnings\Exceptions\PayoutExecutionException;
use App\Models\InstructorWithdrawalRequest;

/**
 * Keyed (HMAC-SHA256, app key) fingerprint — deterministic, but not
 * derivable without the application key, so it is safe to store and
 * compare without leaking the underlying destination details. Mirrors
 * PayoutMethodFingerprintService's keying approach for the same reason:
 * duplicate/replay detection without a brute-forceable plain hash.
 */
final class PayoutRequestFingerprintService implements PayoutRequestFingerprintServiceInterface
{
    public function generate(
        InstructorWithdrawalRequest $withdrawal,
        int $executionSequence,
        string $provider,
        array $destinationSnapshot,
        string $purpose,
    ): string {
        $destinationFingerprint = hash('sha256', json_encode($destinationSnapshot, JSON_THROW_ON_ERROR));

        $material = implode('|', [
            $withdrawal->id,
            (string) $executionSequence,
            (string) $withdrawal->amount_minor,
            $withdrawal->currency_code,
            (string) ($destinationSnapshot['schema_version'] ?? '0'),
            $destinationFingerprint,
            $provider,
            $purpose,
        ]);

        return hash_hmac('sha256', $material, $this->key());
    }

    private function key(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        if ($key === '') {
            throw new PayoutExecutionException('Application encryption key is not configured.');
        }

        return $key;
    }
}
