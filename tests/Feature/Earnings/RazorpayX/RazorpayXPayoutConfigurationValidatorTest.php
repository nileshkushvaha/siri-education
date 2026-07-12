<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings\RazorpayX;

use App\Earnings\Providers\RazorpayX\RazorpayXPayoutConfigurationValidator;
use App\Settings\RazorpayXPayoutSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pure, local, structural validation only — no network call is ever
 * made here (see the validator's own docblock). Every assertion below
 * mutates the settings object in memory only; nothing is persisted.
 */
class RazorpayXPayoutConfigurationValidatorTest extends TestCase
{
    use RefreshDatabase;

    private RazorpayXPayoutConfigurationValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new RazorpayXPayoutConfigurationValidator;
    }

    private function completeSettings(): RazorpayXPayoutSettings
    {
        $settings = app(RazorpayXPayoutSettings::class);

        $settings->razorpayx_key_id = 'rzp_test_abc123XYZ';
        $settings->razorpayx_key_secret = 'encrypted-secret';
        $settings->razorpayx_webhook_secret = 'encrypted-webhook-secret';
        $settings->razorpayx_account_number = '1234567890';
        $settings->razorpayx_environment = 'test';
        $settings->razorpayx_expected_outbound_ips = ['203.0.113.10'];
        $settings->razorpayx_ip_allowlisting_confirmed_at = now()->toIso8601String();
        $settings->razorpayx_default_mode = 'IMPS';
        $settings->razorpayx_default_purpose = 'payout';

        return $settings;
    }

    public function test_complete_settings_are_structurally_valid(): void
    {
        $settings = $this->completeSettings();

        $this->assertSame([], $this->validator->issues($settings));
        $this->assertTrue($this->validator->isStructurallyValid($settings));
    }

    public function test_missing_key_id_is_an_issue(): void
    {
        $settings = $this->completeSettings();
        $settings->razorpayx_key_id = null;

        $this->assertNotEmpty($this->validator->issues($settings));
    }

    public function test_malformed_key_id_is_an_issue(): void
    {
        $settings = $this->completeSettings();
        $settings->razorpayx_key_id = 'not-a-real-key';

        $this->assertNotEmpty($this->validator->issues($settings));
    }

    public function test_missing_key_secret_is_an_issue(): void
    {
        $settings = $this->completeSettings();
        $settings->razorpayx_key_secret = null;

        $this->assertContains(
            'RazorpayX key_secret is missing.',
            $this->validator->issues($settings),
        );
    }

    public function test_missing_webhook_secret_is_an_issue(): void
    {
        $settings = $this->completeSettings();
        $settings->razorpayx_webhook_secret = null;

        $this->assertContains(
            'RazorpayX webhook_secret is missing.',
            $this->validator->issues($settings),
        );
    }

    public function test_missing_account_number_is_an_issue(): void
    {
        $settings = $this->completeSettings();
        $settings->razorpayx_account_number = null;

        $this->assertContains(
            'RazorpayX source account number is missing.',
            $this->validator->issues($settings),
        );
    }

    public function test_invalid_environment_is_an_issue(): void
    {
        $settings = $this->completeSettings();
        $settings->razorpayx_environment = 'staging';

        $this->assertNotEmpty($this->validator->issues($settings));
    }

    public function test_empty_outbound_ips_is_an_issue(): void
    {
        $settings = $this->completeSettings();
        $settings->razorpayx_expected_outbound_ips = [];

        $this->assertContains(
            'No expected outbound IP address is configured.',
            $this->validator->issues($settings),
        );
    }

    /** §4: IP allowlisting must be explicitly admin-confirmed, never inferred from a value merely being present. */
    public function test_unconfirmed_ip_allowlisting_is_an_issue_even_with_ips_listed(): void
    {
        $settings = $this->completeSettings();
        $settings->razorpayx_ip_allowlisting_confirmed_at = null;

        $this->assertContains(
            'IP allowlisting has not been explicitly confirmed by an admin.',
            $this->validator->issues($settings),
        );
    }

    public function test_invalid_transfer_mode_is_an_issue(): void
    {
        $settings = $this->completeSettings();
        $settings->razorpayx_default_mode = 'UPI';

        $this->assertNotEmpty($this->validator->issues($settings));
    }

    public function test_invalid_purpose_is_an_issue(): void
    {
        $settings = $this->completeSettings();
        $settings->razorpayx_default_purpose = 'donation';

        $this->assertNotEmpty($this->validator->issues($settings));
    }

    public function test_live_key_with_test_environment_is_a_mismatch_issue(): void
    {
        $settings = $this->completeSettings();
        $settings->razorpayx_key_id = 'rzp_live_abc123XYZ';
        $settings->razorpayx_environment = 'test';

        $issues = $this->validator->issues($settings);
        $this->assertNotEmpty($issues);
        $this->assertTrue(collect($issues)->contains(fn (string $i): bool => str_contains($i, 'live key but the environment is set to test')));
    }

    public function test_test_key_with_live_environment_is_a_mismatch_issue(): void
    {
        $settings = $this->completeSettings();
        $settings->razorpayx_key_id = 'rzp_test_abc123XYZ';
        $settings->razorpayx_environment = 'live';

        $issues = $this->validator->issues($settings);
        $this->assertNotEmpty($issues);
        $this->assertTrue(collect($issues)->contains(fn (string $i): bool => str_contains($i, 'test key but the environment is set to live')));
    }
}
