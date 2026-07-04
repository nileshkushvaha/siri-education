<?php

declare(strict_types=1);

namespace App\Booking\Registry;

use App\Booking\Contracts\PaymentProviderInterface;
use App\Booking\Exceptions\BookingException;

/** Registered payment providers, keyed for BookingSettings + webhooks. */
final class PaymentProviderRegistry
{
    /** @var array<string, PaymentProviderInterface> */
    private array $providers = [];

    public function register(PaymentProviderInterface $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function get(string $key): PaymentProviderInterface
    {
        return $this->providers[$key]
            ?? throw new BookingException(sprintf('Payment provider "%s" is not registered.', $key));
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }
}
