<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\PaymentProviderInterface;
use App\Booking\Exceptions\BookingException;
use App\Booking\Payments\FakePaymentProvider;
use App\Booking\Registry\PaymentProviderRegistry;
use App\Models\Country;
use App\Settings\BookingSettings;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Contracts\Foundation\Application;

/**
 * The single safe seam between "which provider is selected" and "may it
 * actually be used right now".
 *
 * Routing order for current()/resolveKey() (Phase 10.2A):
 *   1. Country::payment_routing (JSON: {"provider": "razorpay", "enabled": true})
 *      when a country is given — the pre-existing, previously-unwired
 *      localization-foundation column, reused as-is (see
 *      docs/architecture/phase-10-razorpay-checkout-payment-capture.md).
 *   2. PaymentGatewaySettings::default_provider, when an admin has set it.
 *   3. BookingSettings::payment_provider — the original, still
 *      load-bearing selection knob every existing checkout flow relies on.
 * `fake` is never silently chosen by this order; it only wins if one of
 * the above explicitly names it (and even then, gated below).
 *
 * Things a raw PaymentProviderRegistry::get() does not check, which is
 * why BookingPaymentService and every checkout entry point must go
 * through this class instead:
 *   - payments_enabled is a platform-wide kill switch — false blocks
 *     every provider, fake included.
 *   - allowed_providers, when non-empty, restricts which provider keys
 *     may ever be resolved, regardless of what's configured/enabled.
 *   - the fake provider is a test/local convenience, never a production
 *     payment path — selecting it outside local/testing (or while
 *     fake_enabled is false) fails safely rather than silently
 *     accepting "payment" that moves no money.
 *   - a real provider that is selected but misconfigured (disabled, or
 *     credentials missing/malformed per PaymentProviderConfigValidator)
 *     fails safely at selection time with a clear admin-facing message,
 *     instead of failing deep inside an HTTP call.
 */
final class PaymentProviderResolver
{
    public function __construct(
        private readonly PaymentProviderRegistry $registry,
        private readonly BookingSettings $bookingSettings,
        private readonly PaymentGatewaySettings $gatewaySettings,
        private readonly Application $app,
    ) {}

    /** @throws BookingException when the configured provider cannot be used right now */
    public function current(?string $countryIso2 = null): PaymentProviderInterface
    {
        return $this->resolve($this->resolveKey($countryIso2));
    }

    /** @throws BookingException when the given provider key cannot be used right now */
    public function resolve(string $key): PaymentProviderInterface
    {
        if (! $this->gatewaySettings->payments_enabled) {
            throw new BookingException('Payments are currently disabled platform-wide.');
        }

        if ($this->gatewaySettings->allowed_providers !== [] && ! in_array($key, $this->gatewaySettings->allowed_providers, true)) {
            throw new BookingException(sprintf('Payment provider "%s" is not in the platform\'s allowed-provider list.', $key));
        }

        $provider = $this->registry->get($key);

        if ($key === FakePaymentProvider::KEY) {
            if (! $this->gatewaySettings->fake_enabled) {
                throw new BookingException('The fake payment provider is disabled.');
            }

            if (! $this->app->environment(['local', 'testing'])) {
                throw new BookingException(
                    'The fake payment provider cannot be used outside local/testing environments.',
                );
            }

            return $provider;
        }

        if (! $provider->isConfigured()) {
            throw new BookingException(sprintf(
                'Payment provider "%s" is not enabled or its credentials are missing/invalid.',
                $key,
            ));
        }

        return $provider;
    }

    private function resolveKey(?string $countryIso2): string
    {
        if ($countryIso2 !== null) {
            $routed = $this->countryRoutedProvider($countryIso2);

            if ($routed !== null) {
                return $routed;
            }
        }

        if (filled($this->gatewaySettings->default_provider)) {
            return (string) $this->gatewaySettings->default_provider;
        }

        return $this->bookingSettings->payment_provider;
    }

    private function countryRoutedProvider(string $countryIso2): ?string
    {
        $country = Country::query()->where('iso2', strtoupper($countryIso2))->first(['payment_routing']);
        $routing = $country?->payment_routing;

        if (! is_array($routing) || ($routing['enabled'] ?? true) === false) {
            return null;
        }

        $provider = $routing['provider'] ?? null;

        return filled($provider) ? (string) $provider : null;
    }
}
