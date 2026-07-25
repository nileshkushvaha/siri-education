<?php

declare(strict_types=1);

namespace Tests\Unit\Country;

use App\Country\Enums\CountryFeature;
use App\Country\Services\CountryFeatureResolver;
use App\Models\Country;
use App\Settings\FeatureSettings;
use App\Settings\PaymentGatewaySettings;
use Tests\TestCase;

/**
 * Phase 34 (GAP-029) — pure logic test of the effective-feature
 * formula. No database is touched: `FeatureSettings`/
 * `PaymentGatewaySettings` are plain Spatie Settings DTOs constructed
 * directly (never `load()`ed/`save()`d), and `Country` is a bare model
 * instance with `feature_flags` force-filled — proving the resolver
 * itself issues zero queries of its own.
 */
class CountryFeatureResolverTest extends TestCase
{
    private function features(array $overrides = []): FeatureSettings
    {
        $features = new FeatureSettings;
        $features->demo_lessons_enabled = $overrides['demo_lessons_enabled'] ?? true;
        $features->wallet_enabled = $overrides['wallet_enabled'] ?? true;
        $features->referral_enabled = $overrides['referral_enabled'] ?? true;
        $features->waitlist_enabled = $overrides['waitlist_enabled'] ?? true;
        $features->homework_enabled = $overrides['homework_enabled'] ?? true;
        $features->recording_enabled = $overrides['recording_enabled'] ?? true;
        $features->promotional_credit_enabled = $overrides['promotional_credit_enabled'] ?? true;

        return $features;
    }

    private function paymentGateway(bool $paymentsEnabled = true): PaymentGatewaySettings
    {
        $settings = new PaymentGatewaySettings;
        $settings->payments_enabled = $paymentsEnabled;

        return $settings;
    }

    private function resolver(array $featureOverrides = [], bool $paymentsEnabled = true): CountryFeatureResolver
    {
        return new CountryFeatureResolver($this->features($featureOverrides), $this->paymentGateway($paymentsEnabled));
    }

    private function country(array $flags): Country
    {
        return (new Country)->forceFill(['feature_flags' => $flags]);
    }

    // ── 1. No override inherits global behavior ─────────────────────────

    public function test_no_override_inherits_global_enabled(): void
    {
        $resolver = $this->resolver();

        $this->assertTrue($resolver->isEnabled(CountryFeature::Wallet, $this->country([])));
        $this->assertTrue($resolver->isEnabled(CountryFeature::Wallet, null));
    }

    public function test_no_override_inherits_global_disabled(): void
    {
        $resolver = $this->resolver(['wallet_enabled' => false]);

        $this->assertFalse($resolver->isEnabled(CountryFeature::Wallet, $this->country([])));
    }

    // ── 2. Country disable overrides global enable ──────────────────────

    public function test_country_disable_overrides_global_enable(): void
    {
        $resolver = $this->resolver(['wallet_enabled' => true]);

        $this->assertFalse($resolver->isEnabled(CountryFeature::Wallet, $this->country(['wallet' => false])));
    }

    // ── 3. Country enable cannot bypass global disable ──────────────────

    public function test_country_enable_cannot_bypass_global_disable(): void
    {
        $resolver = $this->resolver(['wallet_enabled' => false]);

        $this->assertFalse($resolver->isEnabled(CountryFeature::Wallet, $this->country(['wallet' => true])));
    }

    // ── 4. Dependency validation ─────────────────────────────────────────

    public function test_wallet_recharge_falls_when_wallet_is_globally_disabled_even_if_country_enables_recharge(): void
    {
        $resolver = $this->resolver(['wallet_enabled' => false]);

        $this->assertFalse($resolver->isEnabled(CountryFeature::WalletRecharge, $this->country(['wallet_recharge' => true])));
    }

    public function test_wallet_recharge_falls_when_country_disables_wallet_even_though_recharge_itself_is_not_disabled(): void
    {
        $resolver = $this->resolver(['wallet_enabled' => true]);

        $this->assertFalse($resolver->isEnabled(CountryFeature::WalletRecharge, $this->country(['wallet' => false])));
    }

    public function test_promotional_credits_require_wallet(): void
    {
        $resolver = $this->resolver(['wallet_enabled' => true, 'promotional_credit_enabled' => true]);

        $this->assertTrue($resolver->isEnabled(CountryFeature::PromotionalCredits, $this->country([])));
        $this->assertFalse($resolver->isEnabled(CountryFeature::PromotionalCredits, $this->country(['wallet' => false])));
    }

    public function test_wallet_recharge_is_effective_when_both_wallet_and_recharge_remain_enabled(): void
    {
        $resolver = $this->resolver(['wallet_enabled' => true]);

        $this->assertTrue($resolver->isEnabled(CountryFeature::WalletRecharge, $this->country([])));
    }

    // ── 5. Missing country falls through to global-only ─────────────────

    public function test_missing_country_falls_through_to_global_only(): void
    {
        $enabledResolver = $this->resolver(['referral_enabled' => true]);
        $disabledResolver = $this->resolver(['referral_enabled' => false]);

        $this->assertTrue($enabledResolver->isEnabled(CountryFeature::Referrals, null));
        $this->assertFalse($disabledResolver->isEnabled(CountryFeature::Referrals, null));
    }

    // ── 6. Country isolation ─────────────────────────────────────────────

    public function test_disabling_a_feature_for_one_country_does_not_affect_another(): void
    {
        $resolver = $this->resolver(['waitlist_enabled' => true]);

        $countryA = $this->country(['waitlist' => false]);
        $countryB = $this->country([]);

        $this->assertFalse($resolver->isEnabled(CountryFeature::Waitlist, $countryA));
        $this->assertTrue($resolver->isEnabled(CountryFeature::Waitlist, $countryB));
    }

    // ── 7. Recording/global-gate composition ─────────────────────────────

    public function test_recording_country_override_composes_with_global_gate(): void
    {
        $enabledResolver = $this->resolver(['recording_enabled' => true]);
        $disabledResolver = $this->resolver(['recording_enabled' => false]);

        $this->assertTrue($enabledResolver->isEnabled(CountryFeature::RecordingAvailability, $this->country([])));
        $this->assertFalse($enabledResolver->isEnabled(CountryFeature::RecordingAvailability, $this->country(['recording_availability' => false])));
        $this->assertFalse($disabledResolver->isEnabled(CountryFeature::RecordingAvailability, $this->country(['recording_availability' => true])));
    }

    // ── 8. Unknown keys in a stored flags array are read defensively ────

    public function test_unrecognized_stored_keys_are_ignored_defensively_on_read(): void
    {
        $resolver = $this->resolver();

        // Reaching the model directly (bypassing the write-time
        // validator) with legacy/foreign junk must never crash a read.
        $country = $this->country(['not_a_real_feature' => false]);

        $this->assertTrue($resolver->isEnabled(CountryFeature::Wallet, $country));
    }

    // ── 9. Paid bookings reads the payment-gateway kill switch ──────────

    public function test_paid_bookings_reads_the_payment_gateway_global_switch(): void
    {
        $resolver = $this->resolver(paymentsEnabled: false);

        $this->assertFalse($resolver->isEnabled(CountryFeature::PaidBookings, null));
    }
}
