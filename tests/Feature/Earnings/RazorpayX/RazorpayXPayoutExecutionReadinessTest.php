<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings\RazorpayX;

use App\Earnings\Contracts\FinancialFeatureConfigurationServiceInterface;
use App\Settings\RazorpayXPayoutSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ManagesFinancialSettings;
use Tests\TestCase;

/**
 * §21 — evaluatePayoutExecutionReadiness() must factor in RazorpayX-
 * specific checks only when it is the configured provider, and must
 * never itself enable payout execution (that stays a separate, explicit
 * admin action — see FinancialFeatureConfigurationServiceTest for the
 * generic non-RazorpayX-specific readiness coverage).
 */
class RazorpayXPayoutExecutionReadinessTest extends TestCase
{
    use ManagesFinancialSettings;
    use RefreshDatabase;

    private function baseReadySettings(): void
    {
        $this->setFinancialSettings(['earnings_enabled' => true, 'withdrawals_enabled' => true, 'payout_provider' => 'razorpayx']);
    }

    public function test_razorpayx_checks_are_skipped_when_a_different_provider_is_configured(): void
    {
        $this->setFinancialSettings(['earnings_enabled' => true, 'withdrawals_enabled' => true, 'payout_provider' => 'fake']);

        $readiness = app(FinancialFeatureConfigurationServiceInterface::class)->evaluatePayoutExecutionReadiness();

        $this->assertFalse(collect($readiness->blockingCodes)->contains(fn (string $c): bool => str_starts_with($c, 'razorpayx_')));
    }

    public function test_disabled_razorpayx_blocks_readiness(): void
    {
        $this->baseReadySettings();
        $settings = app(RazorpayXPayoutSettings::class);
        $settings->razorpayx_enabled = false;
        $settings->save();

        $readiness = app(FinancialFeatureConfigurationServiceInterface::class)->evaluatePayoutExecutionReadiness();

        $this->assertContains('razorpayx_disabled', $readiness->blockingCodes);
        $this->assertFalse($readiness->isReady);
    }

    public function test_disabled_contact_provisioning_blocks_readiness(): void
    {
        $this->baseReadySettings();
        $settings = $this->fullyConfiguredRazorpayXSettings();
        $settings->razorpayx_contact_provisioning_enabled = false;
        $settings->save();

        $readiness = app(FinancialFeatureConfigurationServiceInterface::class)->evaluatePayoutExecutionReadiness();

        $this->assertContains('razorpayx_contact_provisioning_disabled', $readiness->blockingCodes);
    }

    public function test_disabled_fund_account_provisioning_blocks_readiness(): void
    {
        $this->baseReadySettings();
        $settings = $this->fullyConfiguredRazorpayXSettings();
        $settings->razorpayx_fund_account_provisioning_enabled = false;
        $settings->save();

        $readiness = app(FinancialFeatureConfigurationServiceInterface::class)->evaluatePayoutExecutionReadiness();

        $this->assertContains('razorpayx_fund_account_provisioning_disabled', $readiness->blockingCodes);
    }

    public function test_missing_credentials_surface_as_configuration_invalid(): void
    {
        $this->baseReadySettings();

        $readiness = app(FinancialFeatureConfigurationServiceInterface::class)->evaluatePayoutExecutionReadiness();

        $this->assertContains('razorpayx_configuration_invalid', $readiness->blockingCodes);
    }

    /** §40 definition of done: never claim readiness merely because switches are flipped — real DB constraints are checked too. */
    public function test_a_fully_configured_razorpayx_still_requires_the_destination_link_table(): void
    {
        $this->baseReadySettings();
        $this->fullyConfiguredRazorpayXSettings();

        Schema::drop('instructor_payout_destination_provider_links');

        $readiness = app(FinancialFeatureConfigurationServiceInterface::class)->evaluatePayoutExecutionReadiness();

        $this->assertContains('razorpayx_destination_link_table_missing', $readiness->blockingCodes);
    }

    private function fullyConfiguredRazorpayXSettings(): RazorpayXPayoutSettings
    {
        $settings = app(RazorpayXPayoutSettings::class);
        $settings->razorpayx_enabled = true;
        $settings->razorpayx_environment = 'test';
        $settings->razorpayx_key_id = 'rzp_test_abc123XYZ';
        $settings->razorpayx_key_secret = 'encrypted-secret';
        $settings->razorpayx_webhook_secret = 'encrypted-webhook-secret';
        $settings->razorpayx_account_number = '1234567890';
        $settings->razorpayx_expected_outbound_ips = ['203.0.113.10'];
        $settings->razorpayx_ip_allowlisting_confirmed_at = now()->toIso8601String();
        $settings->razorpayx_default_mode = 'IMPS';
        $settings->razorpayx_default_purpose = 'payout';
        $settings->razorpayx_contact_provisioning_enabled = true;
        $settings->razorpayx_fund_account_provisioning_enabled = true;
        $settings->save();

        return $settings;
    }
}
