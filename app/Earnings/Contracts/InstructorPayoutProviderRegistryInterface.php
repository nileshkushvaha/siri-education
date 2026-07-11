<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Earnings\Exceptions\PayoutProviderException;

/** Registered payout providers, keyed for settings + resolution. Fake-only in Phase 16A. */
interface InstructorPayoutProviderRegistryInterface
{
    public function register(InstructorPayoutProviderInterface $provider): void;

    /** @throws PayoutProviderException when the key is not registered */
    public function get(string $key): InstructorPayoutProviderInterface;

    public function has(string $key): bool;
}
