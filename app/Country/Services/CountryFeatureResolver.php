<?php

declare(strict_types=1);

namespace App\Country\Services;

use App\Country\Enums\CountryFeature;
use App\Models\Country;
use App\Settings\FeatureSettings;
use App\Settings\PaymentGatewaySettings;

/**
 * The single authority on the effective-feature formula:
 *
 *     effective = global feature enabled
 *                 AND every dependency is also effective
 *                 AND the country override does not disable it
 *
 * A country override can only ever narrow (disable) an already
 * globally-enabled feature — it can never turn on a globally-disabled
 * one, and it never bypasses eligibility, safety, payment, lifecycle,
 * or authorization rules, which remain the sole responsibility of each
 * domain's own service/policy.
 *
 * All consumers must call this resolver rather than reading
 * `$country->feature_flags[...]` directly (SRS §21.38, requirement #9).
 *
 * Purely in-memory: `$features`/`$paymentGateway` are container-cached
 * Spatie Settings singletons and `$country->feature_flags` is an
 * already-loaded array cast, so calling this repeatedly for the same
 * request never issues a repeated settings/DB query.
 */
final class CountryFeatureResolver
{
    public function __construct(
        private readonly FeatureSettings $features,
        private readonly PaymentGatewaySettings $paymentGateway,
    ) {}

    /**
     * `$country` is null when no country could be resolved for this
     * action (guest with no default configured, user with no profile
     * country, or resolution deliberately not attempted) — by design,
     * a missing country carries no override to apply
     * — the effective result falls through to the global-only
     * decision, identical to pre-Phase-34 behavior. A resolved but
     * inactive/restricted country is a distinct case and is expected to
     * be rejected by the caller's own eligibility checks before this
     * method is ever reached for a new-initiation boundary.
     */
    public function isEnabled(CountryFeature $feature, ?Country $country): bool
    {
        if (! $this->globallyEnabled($feature)) {
            return false;
        }

        foreach ($feature->dependencies() as $dependency) {
            if (! $this->isEnabled($dependency, $country)) {
                return false;
            }
        }

        if ($country !== null && $this->countryDisables($feature, $country)) {
            return false;
        }

        return true;
    }

    private function globallyEnabled(CountryFeature $feature): bool
    {
        return match ($feature) {
            CountryFeature::PaidBookings => $this->paymentGateway->payments_enabled,
            CountryFeature::DemoLessons => $this->features->demo_lessons_enabled,
            CountryFeature::Wallet => $this->features->wallet_enabled,
            // No separate global "wallet recharge" switch exists — it
            // rides on the same wallet_enabled switch recharge has
            // always read (WalletRechargeService::initiate). The
            // dependency above additionally makes recharge fall when
            // wallet is disabled through the dependency chain too.
            CountryFeature::WalletRecharge => $this->features->wallet_enabled,
            CountryFeature::Referrals => $this->features->referral_enabled,
            CountryFeature::PromotionalCredits => $this->features->promotional_credit_enabled,
            CountryFeature::Waitlist => $this->features->waitlist_enabled,
            CountryFeature::Homework => $this->features->homework_enabled,
            CountryFeature::RecordingAvailability => $this->features->recording_enabled,
        };
    }

    private function countryDisables(CountryFeature $feature, Country $country): bool
    {
        $flags = $country->feature_flags ?? [];

        if (! is_array($flags) || ! array_key_exists($feature->value, $flags)) {
            return false;
        }

        return $flags[$feature->value] === false;
    }
}
