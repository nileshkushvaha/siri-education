<?php

declare(strict_types=1);

namespace App\Booking\Registry;

use App\Booking\Contracts\MeetingProviderInterface;
use App\Booking\Exceptions\BookingException;

/** Registered meeting providers, keyed for MeetingSettings::active_provider. */
final class MeetingProviderRegistry
{
    /** @var array<string, MeetingProviderInterface> */
    private array $providers = [];

    public function register(MeetingProviderInterface $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function get(string $key): MeetingProviderInterface
    {
        return $this->providers[$key]
            ?? throw new BookingException(sprintf('Meeting provider "%s" is not registered.', $key));
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }
}
