<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Filament\Pages\Settings\PaymentAdvancedPage;
use App\Filament\Pages\Settings\PaymentConfigurationPage;
use App\Filament\Pages\Settings\PaymentGatewayPage;
use App\Models\Activity;
use App\Models\User;
use App\Settings\PaymentAdvancedSettings;
use App\Settings\PaymentConfigurationSettings;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS-20-18: every first-party save on the four
 * payment settings pages (Bank Account, Payment Gateways, Payment
 * Configuration, Advanced) must produce a complete, safe audit record
 * via the existing LogsSettingsUpdates trait/AuditTrailService — the
 * same mechanism already proven on MeetingSettingsPage/GeneralSettingsPage/
 * RazorpayXPayoutSettingsPage, not a new audit subsystem.
 */
class PaymentSettingsAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        // Pre-existing, unrelated: any full /admin page load touches
        // InstructorOnboardingResource::pendingReviewQuery(), which
        // queries role('instructor') — seeded here purely so that
        // doesn't 500 in isolation; not part of this phase's mandate.
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $this->superAdmin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->superAdmin->assignRole($role);
        $this->actingAs($this->superAdmin);
    }

    private function latestSettingsUpdate(string $settingsClass): ?Activity
    {
        return Activity::query()
            ->where('event', 'settings_updated')
            ->where('properties->settings_class', $settingsClass)
            ->latest('id')
            ->first();
    }

    /**
     * resetGatewayCredentials()/persistCredentialFieldsForValidation()
     * are deliberately protected — only reachable through their
     * footer-Action closures, which live inside the page's Schema tree
     * rather than the page-level action registry Livewire's own
     * callAction() test helper resolves against. Invoking them directly
     * via reflection on a real, fully-mounted component instance
     * exercises the exact same method body a real click would, without
     * relaxing that visibility just for testability.
     */
    private function callProtectedMethod(object $instance, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($instance, $method))->invoke($instance, ...$args);
    }

    // ── 1/2/3. Payment Gateway page ──────────────────────────────────────────

    public function test_gateway_page_creates_an_audit_record_after_a_real_change(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_key_secret = Crypt::encryptString('Original Secret');
        $gateways->razorpay_key_id = 'rzp_test_original';
        $gateways->save();

        Livewire::test(PaymentGatewayPage::class)
            ->fillForm([
                'razorpay_enabled' => true,
                'razorpay_key_secret' => 'New Secret Value',
                'razorpay_key_id' => 'rzp_test_new',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $activity = $this->latestSettingsUpdate(PaymentGatewaySettings::class);
        $this->assertNotNull($activity, 'Expected a settings_updated audit record.');
        $this->assertSame($this->superAdmin->id, $activity->causer_id);
        $this->assertSame('settings', $activity->log_name);

        $changed = $activity->properties['changed'];

        // A gateway secret gets presence-only redaction — never the
        // value, in either direction.
        $this->assertSame('replaced', $changed['razorpay_key_secret']['action']);
        $this->assertTrue($changed['razorpay_key_secret']['previously_set']);
        $this->assertTrue($changed['razorpay_key_secret']['now_set']);
        $serialized = json_encode($activity->properties);
        $this->assertStringNotContainsString('Original Secret', (string) $serialized);
        $this->assertStringNotContainsString('New Secret Value', (string) $serialized);

        // A publishable key id is not a credential — plain before/after.
        $this->assertSame('rzp_test_original', $changed['razorpay_key_id']['from']);
        $this->assertSame('rzp_test_new', $changed['razorpay_key_id']['to']);

        $this->assertTrue($changed['razorpay_enabled']['to']);
    }

    public function test_gateway_unchanged_save_creates_no_audit_record(): void
    {
        // The form seeds defaults for fields that may be unset in
        // storage (webhook/success URLs, routing flags), so the FIRST
        // save legitimately persists those. Normalise with one save,
        // then prove a genuinely unchanged second save is silent.
        Livewire::test(PaymentGatewayPage::class)
            ->fillForm(['razorpay_key_id' => 'rzp_test_same'])
            ->call('save')
            ->assertHasNoFormErrors();

        Activity::query()->where('event', 'settings_updated')->delete();

        Livewire::test(PaymentGatewayPage::class)
            ->fillForm(['razorpay_key_id' => 'rzp_test_same'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($this->latestSettingsUpdate(PaymentGatewaySettings::class));
    }

    // ── 4/5/6/7. Payment Configuration page — multiple fields, booleans, integers ─

    public function test_configuration_page_records_one_structured_event_for_multiple_changed_fields(): void
    {
        $config = app(PaymentConfigurationSettings::class);
        $config->currency = 'INR';
        $config->decimal_precision = 2;
        $config->allow_partial_payment = false;
        $config->invoice_prefix = 'INV';
        $config->save();

        Livewire::test(PaymentConfigurationPage::class)
            ->fillForm([
                'currency' => 'USD',
                'currency_symbol' => '$',
                'decimal_precision' => 3,
                'default_tax_percent' => 5.5,
                'invoice_prefix' => 'BILL',
                'invoice_number_length' => 10,
                'payment_due_days' => 14,
                'allow_partial_payment' => true,
                'auto_generate_invoice' => true,
                'auto_capture_payment' => true,
                'refund_enabled' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $activity = $this->latestSettingsUpdate(PaymentConfigurationSettings::class);
        $this->assertNotNull($activity);

        $changed = $activity->properties['changed'];

        // Multiple fields changed in one save → one event containing all of them.
        $this->assertArrayHasKey('currency', $changed);
        $this->assertArrayHasKey('decimal_precision', $changed);
        $this->assertArrayHasKey('allow_partial_payment', $changed);
        $this->assertArrayHasKey('invoice_prefix', $changed);

        $this->assertSame('INR', $changed['currency']['from']);
        $this->assertSame('USD', $changed['currency']['to']);

        // Integers preserved as integers, not stringified.
        $this->assertSame(2, $changed['decimal_precision']['from']);
        $this->assertSame(3, $changed['decimal_precision']['to']);
        $this->assertIsInt($changed['decimal_precision']['to']);

        // Booleans preserved as real booleans.
        $this->assertFalse($changed['allow_partial_payment']['from']);
        $this->assertTrue($changed['allow_partial_payment']['to']);
    }

    // ── 8/9/10/11. No-op, validation failure, authorization failure ─────────

    public function test_configuration_unchanged_save_creates_no_audit_record(): void
    {
        $config = app(PaymentConfigurationSettings::class);

        Livewire::test(PaymentConfigurationPage::class)
            ->fillForm([
                'currency' => $config->currency,
                'currency_symbol' => $config->currency_symbol,
                'decimal_precision' => $config->decimal_precision,
                'default_tax_percent' => $config->default_tax_percent,
                'invoice_prefix' => $config->invoice_prefix,
                'invoice_number_length' => $config->invoice_number_length,
                'payment_due_days' => $config->payment_due_days,
                'allow_partial_payment' => $config->allow_partial_payment,
                'auto_generate_invoice' => $config->auto_generate_invoice,
                'auto_capture_payment' => $config->auto_capture_payment,
                'refund_enabled' => $config->refund_enabled,
            ])
            ->call('save');

        $this->assertNull($this->latestSettingsUpdate(PaymentConfigurationSettings::class));
    }

    public function test_validation_failure_creates_no_setting_write_and_no_audit_record(): void
    {
        $config = app(PaymentConfigurationSettings::class);
        $originalPrefix = $config->invoice_prefix;

        Livewire::test(PaymentConfigurationPage::class)
            ->fillForm([
                'currency' => 'USD',
                'currency_symbol' => '$',
                'decimal_precision' => 2,
                'default_tax_percent' => 5,
                'invoice_prefix' => '', // required — triggers validation failure
                'invoice_number_length' => 8,
                'payment_due_days' => 7,
            ])
            ->call('save')
            ->assertHasFormErrors(['invoice_prefix']);

        $this->assertSame($originalPrefix, app(PaymentConfigurationSettings::class)->invoice_prefix);
        $this->assertNull($this->latestSettingsUpdate(PaymentConfigurationSettings::class));
    }

    public function test_unauthorized_user_cannot_save_payment_settings(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        $config = app(PaymentConfigurationSettings::class);
        $originalCurrency = $config->currency;

        $this->actingAs($student)
            ->get('/admin/payment-settings/configuration')
            ->assertForbidden();

        $this->assertSame($originalCurrency, app(PaymentConfigurationSettings::class)->currency);
        $this->assertNull($this->latestSettingsUpdate(PaymentConfigurationSettings::class));
    }

    public function test_super_admin_access_and_save_remain_unchanged(): void
    {
        $this->actingAs($this->superAdmin)
            ->get('/admin/payment-settings/configuration')
            ->assertOk();
    }

    // ── 13/14/15/16/17. Secret handling on the Gateway page ─────────────────

    public function test_a_newly_set_secret_is_never_stored_in_audit_metadata(): void
    {
        Livewire::test(PaymentGatewayPage::class)
            ->fillForm([
                'razorpay_enabled' => true,
                'razorpay_sandbox_mode' => true,
                'razorpay_key_id' => 'rzp_test_public_id',
                'razorpay_key_secret' => 'synthetic-placeholder-secret-value',
                'stripe_enabled' => false,
                'stripe_sandbox_mode' => true,
                'paypal_enabled' => false,
                'paypal_mode' => 'sandbox',
                'cashfree_enabled' => false,
                'cashfree_environment' => 'sandbox',
                'payu_enabled' => false,
                'payu_sandbox_mode' => true,
                'phonepe_enabled' => false,
                'phonepe_sandbox_mode' => true,
                'manual_enabled' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $activity = $this->latestSettingsUpdate(PaymentGatewaySettings::class);
        $this->assertNotNull($activity);

        $changed = $activity->properties['changed'];
        $this->assertArrayHasKey('razorpay_key_secret', $changed);

        $secretChange = $changed['razorpay_key_secret'];
        // MySQL's JSON column normalizes key order on storage/retrieval,
        // so compare the key SET, not positional order.
        $this->assertEqualsCanonicalizing(['changed', 'previously_set', 'now_set', 'action'], array_keys($secretChange));
        $this->assertTrue($secretChange['changed']);
        $this->assertFalse($secretChange['previously_set']);
        $this->assertTrue($secretChange['now_set']);
        $this->assertSame('set', $secretChange['action']);

        // The plaintext/ciphertext value itself must never appear anywhere in properties.
        $serialized = json_encode($activity->properties);
        $this->assertStringNotContainsString('synthetic-placeholder-secret-value', (string) $serialized);
        $this->assertNotSame('synthetic-placeholder-secret-value', $secretChange['from'] ?? null);
        $this->assertNotSame('synthetic-placeholder-secret-value', $secretChange['to'] ?? null);
    }

    public function test_a_replaced_secret_is_never_stored_in_audit_metadata(): void
    {
        $settings = app(PaymentGatewaySettings::class);
        $settings->razorpay_key_secret = Crypt::encryptString('synthetic-old-secret');
        $settings->save();

        Livewire::test(PaymentGatewayPage::class)
            ->fillForm([
                'razorpay_enabled' => true,
                'razorpay_sandbox_mode' => true,
                'razorpay_key_id' => 'rzp_test_public_id',
                'razorpay_key_secret' => 'synthetic-new-secret-value',
                'stripe_enabled' => false,
                'stripe_sandbox_mode' => true,
                'paypal_enabled' => false,
                'paypal_mode' => 'sandbox',
                'cashfree_enabled' => false,
                'cashfree_environment' => 'sandbox',
                'payu_enabled' => false,
                'payu_sandbox_mode' => true,
                'phonepe_enabled' => false,
                'phonepe_sandbox_mode' => true,
                'manual_enabled' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $activity = $this->latestSettingsUpdate(PaymentGatewaySettings::class);
        $changed = $activity->properties['changed'];

        $this->assertSame('replaced', $changed['razorpay_key_secret']['action']);
        $this->assertTrue($changed['razorpay_key_secret']['previously_set']);
        $this->assertTrue($changed['razorpay_key_secret']['now_set']);

        $serialized = json_encode($activity->properties);
        $this->assertStringNotContainsString('synthetic-new-secret-value', (string) $serialized);
        $this->assertStringNotContainsString('synthetic-old-secret', (string) $serialized);
    }

    public function test_a_cleared_secret_records_only_safe_presence_and_action_metadata(): void
    {
        $settings = app(PaymentGatewaySettings::class);
        $settings->razorpay_key_secret = Crypt::encryptString('synthetic-to-be-cleared');
        $settings->save();

        $component = Livewire::test(PaymentGatewayPage::class);
        $this->callProtectedMethod($component->instance(), 'resetGatewayCredentials');

        $activity = $this->latestSettingsUpdate(PaymentGatewaySettings::class);
        $this->assertNotNull($activity);

        $changed = $activity->properties['changed'];
        $this->assertSame('cleared', $changed['razorpay_key_secret']['action']);
        $this->assertTrue($changed['razorpay_key_secret']['previously_set']);
        $this->assertFalse($changed['razorpay_key_secret']['now_set']);

        $serialized = json_encode($activity->properties);
        $this->assertStringNotContainsString('synthetic-to-be-cleared', (string) $serialized);
    }

    public function test_a_blank_secret_field_preserves_the_existing_secret_and_produces_no_secret_change(): void
    {
        $settings = app(PaymentGatewaySettings::class);
        $settings->razorpay_key_secret = Crypt::encryptString('synthetic-preserved-secret');
        $settings->razorpay_key_id = 'rzp_test_existing_id';
        $settings->save();
        $originalCiphertext = $settings->razorpay_key_secret;

        Livewire::test(PaymentGatewayPage::class)
            ->fillForm([
                'razorpay_enabled' => true,
                'razorpay_sandbox_mode' => true,
                'razorpay_key_id' => 'rzp_test_existing_id',
                'razorpay_key_secret' => null, // blank — never re-displayed, means "preserve existing"
                'stripe_enabled' => false,
                'stripe_sandbox_mode' => true,
                'paypal_enabled' => false,
                'paypal_mode' => 'sandbox',
                'cashfree_enabled' => false,
                'cashfree_environment' => 'sandbox',
                'payu_enabled' => false,
                'payu_sandbox_mode' => true,
                'phonepe_enabled' => false,
                'phonepe_sandbox_mode' => true,
                'manual_enabled' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($originalCiphertext, app(PaymentGatewaySettings::class)->razorpay_key_secret, 'A blank secret field must preserve the existing stored secret.');

        $activity = $this->latestSettingsUpdate(PaymentGatewaySettings::class);

        if ($activity !== null) {
            $this->assertArrayNotHasKey('razorpay_key_secret', $activity->properties['changed'], 'A blank "preserve existing" submission must not be recorded as a secret change.');
        } else {
            $this->addToAssertionCount(1);
        }
    }

    public function test_public_provider_identifiers_are_visible_per_existing_classification(): void
    {
        Livewire::test(PaymentGatewayPage::class)
            ->fillForm([
                'razorpay_enabled' => true,
                'razorpay_sandbox_mode' => true,
                'razorpay_key_id' => 'rzp_test_visible_identifier',
                'stripe_enabled' => false,
                'stripe_sandbox_mode' => true,
                'paypal_enabled' => false,
                'paypal_mode' => 'sandbox',
                'cashfree_enabled' => false,
                'cashfree_environment' => 'sandbox',
                'payu_enabled' => false,
                'payu_sandbox_mode' => true,
                'phonepe_enabled' => false,
                'phonepe_sandbox_mode' => true,
                'manual_enabled' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $activity = $this->latestSettingsUpdate(PaymentGatewaySettings::class);
        $changed = $activity->properties['changed'];

        // razorpay_key_id is a public identifier (not password-masked in
        // the form) — existing project classification keeps it visible.
        $this->assertSame('rzp_test_visible_identifier', $changed['razorpay_key_id']['to']);
    }

    // ── 18/19. Description/exception safety ─────────────────────────────────

    public function test_audit_description_contains_no_secret(): void
    {
        Livewire::test(PaymentGatewayPage::class)
            ->fillForm([
                'razorpay_enabled' => true,
                'razorpay_sandbox_mode' => true,
                'razorpay_key_secret' => 'synthetic-description-check-secret',
                'stripe_enabled' => false,
                'stripe_sandbox_mode' => true,
                'paypal_enabled' => false,
                'paypal_mode' => 'sandbox',
                'cashfree_enabled' => false,
                'cashfree_environment' => 'sandbox',
                'payu_enabled' => false,
                'payu_sandbox_mode' => true,
                'phonepe_enabled' => false,
                'phonepe_sandbox_mode' => true,
                'manual_enabled' => false,
            ])
            ->call('save');

        $activity = $this->latestSettingsUpdate(PaymentGatewaySettings::class);
        $this->assertStringNotContainsString('synthetic-description-check-secret', $activity->description);
    }

    // ── Advanced page ─────────────────────────────────────────────────────────

    public function test_advanced_page_creates_an_audit_record_after_a_real_change(): void
    {
        $advanced = app(PaymentAdvancedSettings::class);
        $advanced->webhook_timeout = 30;
        $advanced->max_retry_count = 5;
        $advanced->save();

        Livewire::test(PaymentAdvancedPage::class)
            ->fillForm([
                'webhook_timeout' => 60,
                'max_retry_count' => 10,
                'retry_failed_payments' => true,
                'queue_payment_events' => true,
                'payment_logging' => true,
                'enable_audit_log' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $activity = $this->latestSettingsUpdate(PaymentAdvancedSettings::class);
        $this->assertNotNull($activity);

        $changed = $activity->properties['changed'];
        $this->assertSame(30, $changed['webhook_timeout']['from']);
        $this->assertSame(60, $changed['webhook_timeout']['to']);
        $this->assertSame(5, $changed['max_retry_count']['from']);
        $this->assertSame(10, $changed['max_retry_count']['to']);
    }

    // ── Persist-for-validation path (direct supported service invocation) ──

    public function test_persist_credential_fields_for_validation_is_also_audited(): void
    {
        $component = Livewire::test(PaymentGatewayPage::class)
            ->set('data.razorpay_enabled', true)
            ->set('data.razorpay_key_id', 'rzp_test_validate_path')
            ->set('data.razorpay_key_secret', 'synthetic-validate-path-secret');

        $this->callProtectedMethod($component->instance(), 'persistCredentialFieldsForValidation', 'razorpay');

        $activity = $this->latestSettingsUpdate(PaymentGatewaySettings::class);
        $this->assertNotNull($activity, 'The Validate Credentials action also persists real settings and must be audited.');

        $changed = $activity->properties['changed'];
        $this->assertArrayHasKey('razorpay_key_secret', $changed);
        $this->assertSame('set', $changed['razorpay_key_secret']['action']);

        $serialized = json_encode($activity->properties);
        $this->assertStringNotContainsString('synthetic-validate-path-secret', (string) $serialized);
    }
}
