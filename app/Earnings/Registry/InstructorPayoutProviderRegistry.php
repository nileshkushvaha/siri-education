<?php

declare(strict_types=1);

namespace App\Earnings\Registry;

use App\Earnings\Contracts\InstructorPayoutProviderInterface;
use App\Earnings\Contracts\InstructorPayoutProviderRegistryInterface;
use App\Earnings\Exceptions\PayoutProviderException;

/** Registered payout providers, keyed for settings + resolution. */
final class InstructorPayoutProviderRegistry implements InstructorPayoutProviderRegistryInterface
{
    /** @var array<string, InstructorPayoutProviderInterface> */
    private array $providers = [];

    public function register(InstructorPayoutProviderInterface $provider): void
    {
        $this->providers[$provider->providerName()] = $provider;
    }

    public function get(string $key): InstructorPayoutProviderInterface
    {
        return $this->providers[$key]
            ?? throw new PayoutProviderException(sprintf('Payout provider "%s" is not registered.', $key));
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }
}
