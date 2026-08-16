<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Settings\PaymentConfigurationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Payment Settings" is satisfied by 4 existing,
 * already-tested-via-admin-UI classes: PaymentConfigurationSettings,
 * PaymentGatewaySettings, PaymentAdvancedSettings. This
 * test covers PaymentConfigurationSettings as the representative core
 * of that group — see docs/architecture/platform-settings-feature-flags.md.
 */
class PaymentSettingsLoadUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/settings']);
    }

    public function test_payment_configuration_settings_load_defaults(): void
    {
        $settings = app(PaymentConfigurationSettings::class);

        $this->assertSame('INR', $settings->currency);
        $this->assertSame(7, $settings->payment_due_days);
        $this->assertTrue($settings->auto_generate_invoice);
    }

    public function test_payment_configuration_settings_can_be_updated(): void
    {
        $settings = app(PaymentConfigurationSettings::class);
        $settings->currency = 'USD';
        $settings->currency_symbol = '$';
        $settings->payment_due_days = 14;
        $settings->save();

        $fresh = app()->make(PaymentConfigurationSettings::class)->refresh();

        $this->assertSame('USD', $fresh->currency);
        $this->assertSame(14, $fresh->payment_due_days);
    }
}
