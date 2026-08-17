<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Booking\Enums\PaymentCollectionRolloutScope;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\PaymentGatewaySettings;

/**
 * The minimum ACTIVE MARKET a wallet recharge needs to exist in.
 *
 * Wallet recharge is external money collection, so it passes the same
 * PaymentCollectionEligibilityService gate as booking collection: an
 * active `Country` with an enabled payment route, plus platform-wide
 * payments enabled under a live rollout scope. Recharge previously
 * consulted only the resolved provider's own currency list, which is why
 * wallet fixtures never needed a country — and why a student in an
 * inactive or unlaunched market could fund a wallet.
 *
 * Shared here rather than repeated per test class so the fixtures cannot
 * drift into describing a market shape the service would refuse (or, as
 * before, a shape the service was never checking).
 */
trait EstablishesRechargeMarket
{
    /**
     * An active market routing to $provider, with $currencyCode as its
     * billing currency. Returns the Country so a test can attach
     * students to it.
     */
    protected function establishRechargeMarket(
        string $iso2,
        string $currencyCode,
        string $provider = 'fake',
        string $numericCode = '000',
    ): Country {
        $currency = Currency::query()->firstOrCreate(['code' => $currencyCode], [
            'name' => $currencyCode,
            'symbol' => $currencyCode,
            'numeric_code' => $numericCode,
            'minor_units' => 2,
            'status' => 'active',
        ]);

        $country = Country::query()->updateOrCreate(['iso2' => $iso2], [
            'name' => $iso2,
            'status' => 'active',
            'default_currency_id' => $currency->id,
            // First step of PaymentProviderResolver's routing order, so
            // this — not BookingSettings::payment_provider — decides the
            // provider a test actually exercises.
            'payment_routing' => ['provider' => $provider, 'enabled' => true],
        ]);

        $gateways = app(PaymentGatewaySettings::class);
        $gateways->payments_enabled = true;
        $gateways->payment_collection_rollout_scope = PaymentCollectionRolloutScope::ActiveCountryRouting->value;
        $gateways->save();

        return $country;
    }

    /** Re-routes an already-established market at a different provider. */
    protected function routeMarketTo(Country $country, string $provider): void
    {
        Country::query()->whereKey($country->id)->update([
            'payment_routing' => json_encode(['provider' => $provider, 'enabled' => true]),
        ]);
    }

    protected function attachStudentToMarket(User $student, Country $country): User
    {
        UserProfile::updateOrCreate(['user_id' => $student->id], ['country_id' => $country->id]);

        return $student->refresh();
    }
}
