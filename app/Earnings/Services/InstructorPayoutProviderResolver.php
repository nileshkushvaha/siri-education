<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Earnings\Contracts\InstructorPayoutProviderInterface;
use App\Earnings\Contracts\InstructorPayoutProviderRegistryInterface;
use App\Earnings\Contracts\InstructorPayoutProviderResolverInterface;
use App\Earnings\Exceptions\PayoutProviderException;
use App\Earnings\Providers\Fake\FakeInstructorPayoutProvider;
use App\Settings\InstructorEarningSettings;
use Illuminate\Contracts\Foundation\Application;

/**
 * The single safe seam between "which payout provider is configured"
 * and "may it actually execute a payout right now" — mirrors
 * Booking\Services\PaymentProviderResolver's role for checkout,
 * deliberately kept as a separate class (see
 * InstructorPayoutProviderInterface's docblock for why the two
 * boundaries are not merged).
 *
 * A raw InstructorPayoutProviderRegistry::get() does not check:
 *   - payout_execution_enabled — the platform-wide kill switch; false
 *     blocks every provider, fake included.
 *   - the fake provider is a test/local convenience, never a
 *     production payout path — selecting it outside local/testing (and
 *     without the explicit staging-simulation flag) fails safely.
 *   - currency support and a passing health check.
 */
final class InstructorPayoutProviderResolver implements InstructorPayoutProviderResolverInterface
{
    public function __construct(
        private readonly InstructorPayoutProviderRegistryInterface $registry,
        private readonly InstructorEarningSettings $settings,
        private readonly Application $app,
    ) {}

    public function current(string $currencyCode): InstructorPayoutProviderInterface
    {
        return $this->resolve($this->settings->payout_provider, $currencyCode);
    }

    public function resolve(string $key, string $currencyCode): InstructorPayoutProviderInterface
    {
        if (! $this->settings->payout_execution_enabled) {
            throw new PayoutProviderException('Payout execution is currently disabled platform-wide.');
        }

        $provider = $this->registry->get($key);

        if ($key === FakeInstructorPayoutProvider::KEY) {
            $stagingAllowed = $this->settings->payout_fake_provider_staging_enabled;

            if (! $this->app->environment(['local', 'testing']) && ! $stagingAllowed) {
                throw new PayoutProviderException(
                    'The fake payout provider cannot be used outside local/testing environments.',
                );
            }
        }

        if (! $provider->supportsCurrency($currencyCode)) {
            throw new PayoutProviderException(sprintf('Payout provider "%s" does not support currency "%s".', $key, $currencyCode));
        }

        $health = $provider->healthCheck();

        if (! $health->healthy) {
            throw new PayoutProviderException(sprintf('Payout provider "%s" is not currently healthy: %s', $key, $health->safeMessage ?? 'unknown reason'));
        }

        return $provider;
    }
}
