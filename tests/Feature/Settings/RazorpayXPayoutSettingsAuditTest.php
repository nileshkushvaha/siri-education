<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXHealthResult;
use App\Earnings\Providers\RazorpayX\RazorpayXPayoutClientInterface;
use App\Filament\Pages\Settings\RazorpayXPayoutSettingsPage;
use App\Models\Activity;
use App\Models\User;
use App\Settings\RazorpayXPayoutSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RazorpayXPayoutSettingsPage previously had no test coverage. Its main
 * save() called an undefined
 * saveEncryptedField() method (a real, previously-unreachable-without-
 * crashing production bug — this class never extended PaymentSettingsPage,
 * which is where that helper actually lives) — fixed inline as part of
 * converting this page onto the atomic+audited path. All six mutation
 * methods (save, validateConfiguration, checkHealth, confirmIpAllowlisting,
 * rotateWebhookSecret, clearInvalidCredentials) previously wrote settings
 * with no audit trail at all except the main save().
 */
class RazorpayXPayoutSettingsAuditTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

        return $admin;
    }

    private function latestUpdate(): ?Activity
    {
        return Activity::query()
            ->where('event', 'settings_updated')
            ->where('properties->settings_class', RazorpayXPayoutSettings::class)
            ->latest('id')
            ->first();
    }

    private function formBaseline(array $overrides = []): array
    {
        return array_merge([
            'data.razorpayx_enabled' => true,
            'data.razorpayx_environment' => 'test',
            'data.razorpayx_key_id' => 'rzp_test_key_id',
            'data.razorpayx_account_number' => '1234567890',
            'data.razorpayx_default_mode' => 'IMPS',
            'data.razorpayx_default_purpose' => 'payout',
            'data.razorpayx_queue_if_low_balance' => false,
            'data.razorpayx_contact_provisioning_enabled' => false,
            'data.razorpayx_fund_account_provisioning_enabled' => false,
        ], $overrides);
    }

    // ── save() — was previously fatal (undefined saveEncryptedField()) ──────

    public function test_save_persists_and_audits_and_the_previously_undefined_method_no_longer_crashes(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(RazorpayXPayoutSettingsPage::class)
            ->set($this->formBaseline())
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('RazorpayX settings saved');

        $settings = app(RazorpayXPayoutSettings::class)->refresh();
        $this->assertTrue($settings->razorpayx_enabled);
        $this->assertSame('rzp_test_key_id', $settings->razorpayx_key_id);

        $activity = $this->latestUpdate();
        $this->assertNotNull($activity);
        $this->assertSame('razorpayx_payout', $activity->log_name);
    }

    public function test_a_newly_set_key_secret_and_the_financial_account_number_are_never_stored_in_plaintext(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(RazorpayXPayoutSettingsPage::class)
            ->set($this->formBaseline([
                'data.razorpayx_key_secret' => 'synthetic-razorpayx-key-secret',
                'data.razorpayx_webhook_secret' => 'synthetic-razorpayx-webhook-secret',
            ]))
            ->call('save')
            ->assertHasNoFormErrors();

        $activity = $this->latestUpdate();
        $changed = $activity->properties['changed'];

        $this->assertSame('set', $changed['razorpayx_key_secret']['action']);
        $this->assertSame('set', $changed['razorpayx_webhook_secret']['action']);
        // The account number is a financial identifier — presence-only too.
        $this->assertSame('set', $changed['razorpayx_account_number']['action']);
        // The public key_id remains a plain, visible before/after value.
        $this->assertSame('rzp_test_key_id', $changed['razorpayx_key_id']['to']);

        $serialized = (string) json_encode($activity->properties);
        $this->assertStringNotContainsString('synthetic-razorpayx-key-secret', $serialized);
        $this->assertStringNotContainsString('synthetic-razorpayx-webhook-secret', $serialized);
        $this->assertStringNotContainsString('1234567890', $serialized);
    }

    public function test_saving_with_no_changes_creates_no_audit_event(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(RazorpayXPayoutSettingsPage::class)
            ->set($this->formBaseline())
            ->call('save');
        $afterFirstSave = $this->latestUpdate();
        $this->assertNotNull($afterFirstSave);

        Livewire::test(RazorpayXPayoutSettingsPage::class)
            ->set($this->formBaseline())
            ->call('save');

        $this->assertSame($afterFirstSave->id, $this->latestUpdate()->id, 'An unchanged resubmission must not create a second event.');
    }

    public function test_unauthorized_user_cannot_save_razorpayx_settings(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        $this->actingAs($student)
            ->get('/admin/settings/razorpayx-payout')
            ->assertForbidden();

        $this->assertNull($this->latestUpdate());
    }

    // ── validateConfiguration() — local-only, previously unaudited ──────────

    public function test_validate_configuration_persists_and_audits_the_status(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(RazorpayXPayoutSettingsPage::class)
            ->call('validateConfiguration');

        $settings = app(RazorpayXPayoutSettings::class)->refresh();
        $this->assertSame('invalid', $settings->razorpayx_config_status); // unconfigured by default

        $activity = $this->latestUpdate();
        $this->assertNotNull($activity);
        $this->assertSame('not_configured', $activity->properties['changed']['razorpayx_config_status']['from']);
        $this->assertSame('invalid', $activity->properties['changed']['razorpayx_config_status']['to']);
    }

    // ── checkHealth() — external call happens before persistence, never inside the audited transaction ──

    public function test_check_health_persists_and_audits_without_any_real_http_call(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $client = Mockery::mock(RazorpayXPayoutClientInterface::class);
        $client->shouldReceive('fetchBalanceOrHealth')->once()->andReturn(new RazorpayXHealthResult(healthy: true));
        $this->app->instance(RazorpayXPayoutClientInterface::class, $client);

        // Structurally configured, so healthCheck() actually calls the client.
        $settings = app(RazorpayXPayoutSettings::class);
        $settings->razorpayx_enabled = true;
        $settings->razorpayx_key_id = 'rzp_test_id';
        $settings->razorpayx_key_secret = 'seed-secret';
        $settings->razorpayx_webhook_secret = 'seed-webhook-secret';
        $settings->razorpayx_account_number = '1234567890';
        $settings->razorpayx_expected_outbound_ips = ['203.0.113.10'];
        $settings->razorpayx_ip_allowlisting_confirmed_at = now()->toIso8601String();
        $settings->save();

        $this->actingAs($this->admin());

        Livewire::test(RazorpayXPayoutSettingsPage::class)
            ->call('checkHealth')
            ->assertNotified('RazorpayX is reachable');

        $this->assertSame('healthy', app(RazorpayXPayoutSettings::class)->refresh()->razorpayx_last_health_status);

        $activity = $this->latestUpdate();
        $this->assertNotNull($activity);
        $this->assertSame('healthy', $activity->properties['changed']['razorpayx_last_health_status']['to']);

        Http::assertNothingSent();
    }

    // ── confirmIpAllowlisting() — a real compliance attestation, previously unaudited ──

    public function test_confirm_ip_allowlisting_persists_and_audits_the_confirming_admin(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        Livewire::test(RazorpayXPayoutSettingsPage::class)
            ->call('confirmIpAllowlisting')
            ->assertNotified('IP allowlisting confirmed');

        $settings = app(RazorpayXPayoutSettings::class)->refresh();
        $this->assertNotNull($settings->razorpayx_ip_allowlisting_confirmed_at);
        $this->assertSame($admin->id, $settings->razorpayx_ip_allowlisting_confirmed_by);

        $activity = $this->latestUpdate();
        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->properties['changed']['razorpayx_ip_allowlisting_confirmed_by']['to']);
    }

    // ── rotateWebhookSecret() — previously entirely unaudited ────────────────

    public function test_rotate_webhook_secret_persists_and_audits_without_leaking_either_secret(): void
    {
        $settings = app(RazorpayXPayoutSettings::class);
        $settings->razorpayx_webhook_secret = Crypt::encryptString('synthetic-old-webhook-secret');
        $settings->save();

        $this->actingAs($this->admin());

        $component = Livewire::test(RazorpayXPayoutSettingsPage::class);
        (new ReflectionMethod($component->instance(), 'rotateWebhookSecret'))->invoke($component->instance(), 'synthetic-new-webhook-secret');

        $activity = $this->latestUpdate();
        $this->assertNotNull($activity);
        $changed = $activity->properties['changed'];
        $this->assertSame('replaced', $changed['razorpayx_webhook_secret']['action']);
        $this->assertSame('set', $changed['razorpayx_previous_webhook_secret']['action']);

        $serialized = (string) json_encode($activity->properties);
        $this->assertStringNotContainsString('synthetic-old-webhook-secret', $serialized);
        $this->assertStringNotContainsString('synthetic-new-webhook-secret', $serialized);
    }

    // ── clearInvalidCredentials() — a clear/reset action, previously unaudited ──

    public function test_clear_invalid_credentials_persists_and_audits_the_clear_action(): void
    {
        $settings = app(RazorpayXPayoutSettings::class);
        $settings->razorpayx_key_secret = Crypt::encryptString('synthetic-to-clear');
        $settings->razorpayx_webhook_secret = Crypt::encryptString('synthetic-to-clear-2');
        $settings->razorpayx_config_status = 'invalid';
        $settings->save();

        $this->actingAs($this->admin());

        Livewire::test(RazorpayXPayoutSettingsPage::class)
            ->call('clearInvalidCredentials')
            ->assertNotified('Credentials cleared');

        $settings = app(RazorpayXPayoutSettings::class)->refresh();
        $this->assertNull($settings->razorpayx_key_secret);
        $this->assertSame('not_configured', $settings->razorpayx_config_status);

        $activity = $this->latestUpdate();
        $this->assertNotNull($activity);
        $changed = $activity->properties['changed'];
        $this->assertSame('cleared', $changed['razorpayx_key_secret']['action']);
        $this->assertSame('cleared', $changed['razorpayx_webhook_secret']['action']);
    }
}
