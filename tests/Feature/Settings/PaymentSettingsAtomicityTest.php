<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Filament\Pages\Settings\LogsSettingsUpdates;
use App\Filament\Pages\Settings\PaymentAdvancedPage;
use App\Filament\Pages\Settings\PaymentBankAccountPage;
use App\Filament\Pages\Settings\PaymentConfigurationPage;
use App\Filament\Pages\Settings\PaymentGatewayPage;
use App\Models\Activity;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Settings\BankSettings;
use App\Settings\PaymentAdvancedSettings;
use App\Settings\PaymentConfigurationSettings;
use App\Settings\PaymentGatewaySettings;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use ReflectionMethod;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24G.1 — corrective continuation of Phase 24G. Closes two
 * remaining risks: (1) a payment settings write and its Critical audit
 * record must commit or roll back together, never one without the
 * other; (2) bank/UPI financial identifiers must never be stored as
 * plaintext before/after values in the audit log just because their
 * form fields aren't password-masked.
 */
class PaymentSettingsAtomicityTest extends TestCase
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

    private function callProtectedMethod(object $instance, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($instance, $method))->invoke($instance, ...$args);
    }

    /**
     * Binds a fake AuditTrailService that always throws, simulating an
     * audit-write failure. AuditTrailService is `final`, so Mockery
     * cannot subclass it — a plain container-bound duck-typed stub
     * works instead, since app(AuditTrailService::class)->logUser(...)
     * is a dynamic call resolved against whatever object the container
     * actually returns.
     */
    private function bindFailingAuditTrailService(): void
    {
        $this->app->instance(AuditTrailService::class, new class
        {
            public function logUser(...$args): never
            {
                throw new RuntimeException('Simulated audit failure');
            }
        });
    }

    /**
     * Exercises LogsSettingsUpdates::saveSettingsWithAudit() directly,
     * without going through a Filament page — used for granular
     * redaction-shape assertions and for simulating a persistence-layer
     * failure that originates before ->save() is ever reached.
     */
    private function atomicHarness(): object
    {
        return new class
        {
            use LogsSettingsUpdates;

            public function run(string $settingsClass, string $logName, Closure $mutate): bool
            {
                return $this->saveSettingsWithAudit($settingsClass, $logName, $mutate);
            }
        };
    }

    // ── 1. Settings + audit commit together (happy path) ─────────────────────

    public function test_a_payment_setting_and_its_audit_record_commit_together(): void
    {
        Livewire::test(PaymentBankAccountPage::class)
            ->fillForm([
                'enable_offline_payment' => true,
                'account_holder_name' => 'Synthetic Holder',
                'bank_name' => 'Synthetic Bank',
                'account_type' => 'savings',
                'account_number' => '999888777666',
                'account_number_confirm' => '999888777666',
                'ifsc_code' => 'ABCD0123456',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('999888777666', app(BankSettings::class)->account_number);
        $this->assertNotNull($this->latestSettingsUpdate(BankSettings::class));
    }

    // ── 2. Forced settings-persistence failure creates no audit record ──────

    public function test_a_forced_settings_persistence_failure_creates_no_audit_record(): void
    {
        $before = app(BankSettings::class)->account_number;

        $ok = $this->atomicHarness()->run(BankSettings::class, 'settings', function (BankSettings $bank): void {
            $bank->account_number = 'SHOULD_NEVER_PERSIST';
            throw new RuntimeException('Simulated settings persistence failure');
        });

        $this->assertFalse($ok);
        $this->assertNull($this->latestSettingsUpdate(BankSettings::class));
        $this->assertSame($before, app(BankSettings::class)->account_number);
    }

    // ── 3/4/5. Forced audit failure rolls back Bank + Gateway settings; reload returns originals ──

    public function test_a_forced_audit_trail_failure_rolls_back_a_bank_settings_change(): void
    {
        $bank = app(BankSettings::class);
        $bank->account_number = 'ORIGINAL_ACCOUNT_1';
        $bank->bank_name = 'Original Bank';
        $bank->save();

        $this->bindFailingAuditTrailService();

        Livewire::test(PaymentBankAccountPage::class)
            ->fillForm([
                'enable_offline_payment' => true,
                'account_holder_name' => 'Someone',
                'bank_name' => 'Attempted New Bank',
                'account_type' => 'savings',
                'account_number' => 'ATTEMPTED_NEW_ACCOUNT',
                'account_number_confirm' => 'ATTEMPTED_NEW_ACCOUNT',
                'ifsc_code' => 'ABCD0123456',
            ])
            ->call('save');

        // Reloading must return the ORIGINAL persisted values, not the
        // attempted (never-committed) ones — proves the rollback
        // actually reached the database and the scoped container was
        // reset (see saveSettingsWithAudit()'s forgetInstance() call).
        $reloaded = app(BankSettings::class);
        $this->assertSame('ORIGINAL_ACCOUNT_1', $reloaded->account_number);
        $this->assertSame('Original Bank', $reloaded->bank_name);
        $this->assertNull($this->latestSettingsUpdate(BankSettings::class));
    }

    public function test_the_same_forced_audit_failure_rolls_back_a_gateway_settings_change(): void
    {
        $settings = app(PaymentGatewaySettings::class);
        $settings->razorpay_key_id = 'rzp_original_id';
        $settings->save();

        $this->bindFailingAuditTrailService();

        Livewire::test(PaymentGatewayPage::class)
            ->fillForm($this->gatewayFormBaseline(['razorpay_key_id' => 'rzp_attempted_new_id']))
            ->call('save');

        $reloaded = app(PaymentGatewaySettings::class);
        $this->assertSame('rzp_original_id', $reloaded->razorpay_key_id);
        $this->assertNull($this->latestSettingsUpdate(PaymentGatewaySettings::class));
    }

    // ── 6. No-op still creates no audit record via the atomic helper ────────

    public function test_a_no_op_save_through_the_atomic_helper_creates_no_audit_record(): void
    {
        $bank = app(BankSettings::class);
        $bank->bank_name = 'Stable Bank';
        $bank->save();

        $ok = $this->atomicHarness()->run(BankSettings::class, 'settings', function (BankSettings $bank): void {
            $bank->bank_name = 'Stable Bank'; // unchanged
        });

        $this->assertTrue($ok);
        $this->assertNull($this->latestSettingsUpdate(BankSettings::class));
    }

    // ── 7/8/9. Financial identifiers never appear in plaintext ──────────────

    public function test_account_number_plaintext_never_appears_in_activity_description_or_properties(): void
    {
        $bank = app(BankSettings::class);
        $bank->account_number = '100000000001';
        $bank->save();

        Livewire::test(PaymentBankAccountPage::class)
            ->fillForm([
                'enable_offline_payment' => true,
                'account_holder_name' => 'Holder',
                'bank_name' => 'Some Bank',
                'account_type' => 'savings',
                'account_number' => '200000000002',
                'account_number_confirm' => '200000000002',
                'ifsc_code' => 'ABCD0123456',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $activity = $this->latestSettingsUpdate(BankSettings::class);
        $this->assertNotNull($activity);

        $changed = $activity->properties['changed'];
        $this->assertSame('replaced', $changed['account_number']['action']);
        $this->assertTrue($changed['account_number']['previously_set']);
        $this->assertTrue($changed['account_number']['now_set']);

        $serialized = (string) json_encode($activity->properties);
        $this->assertStringNotContainsString('100000000001', $serialized);
        $this->assertStringNotContainsString('200000000002', $serialized);
        $this->assertStringNotContainsString('100000000001', $activity->description);
        $this->assertStringNotContainsString('200000000002', $activity->description);
    }

    public function test_upi_id_plaintext_never_appears_in_description_or_properties(): void
    {
        $bank = app(BankSettings::class);
        $bank->upi_id = 'synthetic-old@bank';
        $bank->account_type = 'current';
        $bank->save();

        Livewire::test(PaymentBankAccountPage::class)
            ->fillForm([
                'enable_offline_payment' => false,
                'account_type' => 'current',
                'upi_id' => 'synthetic-new@bank',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $activity = $this->latestSettingsUpdate(BankSettings::class);
        $this->assertNotNull($activity);

        $changed = $activity->properties['changed'];
        $this->assertSame('replaced', $changed['upi_id']['action']);

        $serialized = (string) json_encode($activity->properties);
        $this->assertStringNotContainsString('synthetic-old@bank', $serialized);
        $this->assertStringNotContainsString('synthetic-new@bank', $serialized);
        $this->assertStringNotContainsString('synthetic-new@bank', $activity->description);
    }

    public function test_ifsc_code_value_never_appears_in_description_or_properties(): void
    {
        $bank = app(BankSettings::class);
        $bank->ifsc_code = 'OLDB0000001';
        $bank->save();

        Livewire::test(PaymentBankAccountPage::class)
            ->fillForm([
                'enable_offline_payment' => true,
                'account_holder_name' => 'Holder',
                'bank_name' => 'Some Bank',
                'account_type' => 'savings',
                'account_number' => '123456789012',
                'account_number_confirm' => '123456789012',
                'ifsc_code' => 'NEWB0000002',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $activity = $this->latestSettingsUpdate(BankSettings::class);
        $changed = $activity->properties['changed'];
        $this->assertSame('replaced', $changed['ifsc_code']['action']);

        $serialized = (string) json_encode($activity->properties);
        $this->assertStringNotContainsString('OLDB0000001', $serialized);
        $this->assertStringNotContainsString('NEWB0000002', $serialized);
        $this->assertStringNotContainsString('NEWB0000002', $activity->description);
    }

    // ── 10. Set / replace / clear a financial identifier → safe metadata only ──

    public function test_setting_replacing_and_clearing_a_financial_identifier_records_only_safe_metadata(): void
    {
        $harness = $this->atomicHarness();

        $harness->run(BankSettings::class, 'settings', function (BankSettings $bank): void {
            $bank->iban = 'SYNTHETIC_IBAN_0001';
        });
        $activity = $this->latestSettingsUpdate(BankSettings::class);
        $this->assertSame('set', $activity->properties['changed']['iban']['action']);
        $this->assertFalse($activity->properties['changed']['iban']['previously_set']);
        $this->assertTrue($activity->properties['changed']['iban']['now_set']);

        $harness->run(BankSettings::class, 'settings', function (BankSettings $bank): void {
            $bank->iban = 'SYNTHETIC_IBAN_0002';
        });
        $activity = $this->latestSettingsUpdate(BankSettings::class);
        $this->assertSame('replaced', $activity->properties['changed']['iban']['action']);

        $harness->run(BankSettings::class, 'settings', function (BankSettings $bank): void {
            $bank->iban = null;
        });
        $activity = $this->latestSettingsUpdate(BankSettings::class);
        $this->assertSame('cleared', $activity->properties['changed']['iban']['action']);
        $this->assertTrue($activity->properties['changed']['iban']['previously_set']);
        $this->assertFalse($activity->properties['changed']['iban']['now_set']);

        $serialized = (string) json_encode($activity->properties);
        $this->assertStringNotContainsString('SYNTHETIC_IBAN_0001', $serialized);
        $this->assertStringNotContainsString('SYNTHETIC_IBAN_0002', $serialized);
    }

    // ── 11. Account-holder-name PII classification ───────────────────────────

    public function test_account_holder_name_follows_presence_only_pii_classification(): void
    {
        $bank = app(BankSettings::class);
        $bank->account_holder_name = 'Synthetic Original Holder';
        $bank->save();

        Livewire::test(PaymentBankAccountPage::class)
            ->fillForm([
                'enable_offline_payment' => true,
                'account_holder_name' => 'Synthetic New Holder',
                'bank_name' => 'Some Bank',
                'account_type' => 'savings',
                'account_number' => '123456789012',
                'account_number_confirm' => '123456789012',
                'ifsc_code' => 'ABCD0123456',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $activity = $this->latestSettingsUpdate(BankSettings::class);
        $changed = $activity->properties['changed']['account_holder_name'];

        $this->assertSame('replaced', $changed['action']);
        $serialized = (string) json_encode($activity->properties);
        $this->assertStringNotContainsString('Synthetic Original Holder', $serialized);
        $this->assertStringNotContainsString('Synthetic New Holder', $serialized);
    }

    // ── 12. Safe non-sensitive fields retain accurate before/after values ───

    public function test_bank_name_and_safe_fields_retain_accurate_before_after_values(): void
    {
        $bank = app(BankSettings::class);
        $bank->bank_name = 'Old Safe Bank';
        $bank->branch_name = 'Old Branch';
        $bank->account_type = 'current';
        $bank->save();

        Livewire::test(PaymentBankAccountPage::class)
            ->fillForm([
                'enable_offline_payment' => true,
                'account_holder_name' => 'Holder',
                'bank_name' => 'New Safe Bank',
                'branch_name' => 'New Branch',
                'account_type' => 'savings',
                'account_number' => '123456789012',
                'account_number_confirm' => '123456789012',
                'ifsc_code' => 'ABCD0123456',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $activity = $this->latestSettingsUpdate(BankSettings::class);
        $changed = $activity->properties['changed'];

        $this->assertSame('Old Safe Bank', $changed['bank_name']['from']);
        $this->assertSame('New Safe Bank', $changed['bank_name']['to']);
        $this->assertSame('Old Branch', $changed['branch_name']['from']);
        $this->assertSame('New Branch', $changed['branch_name']['to']);
        $this->assertSame('current', $changed['account_type']['from']);
        $this->assertSame('savings', $changed['account_type']['to']);
    }

    // ── 15. Multiple safe + sensitive changes in one save → one event ───────

    public function test_multiple_safe_and_sensitive_changes_in_one_save_produce_one_structured_event(): void
    {
        $bank = app(BankSettings::class);
        $bank->bank_name = 'Before Bank';
        $bank->account_number = '300000000003';
        $bank->upi_id = 'before@upi';
        $bank->account_type = 'current';
        $bank->save();

        Livewire::test(PaymentBankAccountPage::class)
            ->fillForm([
                'enable_offline_payment' => true,
                'account_holder_name' => 'Holder',
                'bank_name' => 'After Bank',
                'account_type' => 'savings',
                'account_number' => '400000000004',
                'account_number_confirm' => '400000000004',
                'ifsc_code' => 'ABCD0123456',
                'upi_id' => 'after@upi',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $activities = Activity::query()
            ->where('event', 'settings_updated')
            ->where('properties->settings_class', BankSettings::class)
            ->get();

        $this->assertCount(1, $activities, 'Expected exactly one structured audit event for this save.');

        $changed = $activities->first()->properties['changed'];
        $this->assertSame('After Bank', $changed['bank_name']['to']); // safe
        $this->assertSame('replaced', $changed['account_number']['action']); // sensitive
        $this->assertSame('replaced', $changed['upi_id']['action']); // sensitive
    }

    // ── 17. Every payment mutation method uses the atomic audited path ──────

    public function test_save_bank_settings_is_atomic_with_its_audit_record(): void
    {
        $bank = app(BankSettings::class);
        $bank->account_number = 'ATOMIC_BANK_ORIGINAL';
        $bank->save();

        $this->bindFailingAuditTrailService();

        Livewire::test(PaymentBankAccountPage::class)
            ->fillForm([
                'enable_offline_payment' => true,
                'account_holder_name' => 'Holder',
                'bank_name' => 'Bank',
                'account_type' => 'savings',
                'account_number' => 'ATOMIC_BANK_ATTEMPTED',
                'account_number_confirm' => 'ATOMIC_BANK_ATTEMPTED',
                'ifsc_code' => 'ABCD0123456',
            ])
            ->call('save');

        $this->assertSame('ATOMIC_BANK_ORIGINAL', app(BankSettings::class)->account_number);
        $this->assertNull($this->latestSettingsUpdate(BankSettings::class));
    }

    public function test_save_gateway_settings_is_atomic_with_its_audit_record(): void
    {
        $settings = app(PaymentGatewaySettings::class);
        $settings->razorpay_key_id = 'rzp_gw_original_id';
        $settings->save();

        $this->bindFailingAuditTrailService();

        Livewire::test(PaymentGatewayPage::class)
            ->fillForm($this->gatewayFormBaseline(['razorpay_key_id' => 'rzp_gw_attempted_id']))
            ->call('save');

        $this->assertSame('rzp_gw_original_id', app(PaymentGatewaySettings::class)->razorpay_key_id);
        $this->assertNull($this->latestSettingsUpdate(PaymentGatewaySettings::class));
    }

    public function test_save_configuration_settings_is_atomic_with_its_audit_record(): void
    {
        $config = app(PaymentConfigurationSettings::class);
        $config->invoice_prefix = 'ATOMIC_ORIGINAL';
        $config->save();

        $this->bindFailingAuditTrailService();

        Livewire::test(PaymentConfigurationPage::class)
            ->fillForm([
                'currency' => $config->currency,
                'currency_symbol' => $config->currency_symbol,
                'decimal_precision' => $config->decimal_precision,
                'default_tax_percent' => $config->default_tax_percent,
                'invoice_prefix' => 'ATOMIC_ATTEMPTED',
                'invoice_number_length' => $config->invoice_number_length,
                'payment_due_days' => $config->payment_due_days,
                'allow_partial_payment' => $config->allow_partial_payment,
                'auto_generate_invoice' => $config->auto_generate_invoice,
                'auto_capture_payment' => $config->auto_capture_payment,
                'refund_enabled' => $config->refund_enabled,
            ])
            ->call('save');

        $this->assertSame('ATOMIC_ORIGINAL', app(PaymentConfigurationSettings::class)->invoice_prefix);
        $this->assertNull($this->latestSettingsUpdate(PaymentConfigurationSettings::class));
    }

    public function test_save_advanced_settings_is_atomic_with_its_audit_record(): void
    {
        $advanced = app(PaymentAdvancedSettings::class);
        $advanced->max_retry_count = 3;
        $advanced->save();

        $this->bindFailingAuditTrailService();

        Livewire::test(PaymentAdvancedPage::class)
            ->fillForm([
                'webhook_timeout' => $advanced->webhook_timeout,
                'max_retry_count' => 9,
                'retry_failed_payments' => $advanced->retry_failed_payments,
                'queue_payment_events' => $advanced->queue_payment_events,
                'payment_logging' => $advanced->payment_logging,
                'enable_audit_log' => $advanced->enable_audit_log,
            ])
            ->call('save');

        $this->assertSame(3, app(PaymentAdvancedSettings::class)->max_retry_count);
        $this->assertNull($this->latestSettingsUpdate(PaymentAdvancedSettings::class));
    }

    public function test_reset_gateway_credentials_is_atomic_with_its_audit_record(): void
    {
        $settings = app(PaymentGatewaySettings::class);
        $settings->razorpay_key_secret = Crypt::encryptString('synthetic-to-remain');
        $settings->save();

        $this->bindFailingAuditTrailService();

        $component = Livewire::test(PaymentGatewayPage::class);
        $this->callProtectedMethod($component->instance(), 'resetGatewayCredentials');

        $this->assertNotNull(
            app(PaymentGatewaySettings::class)->razorpay_key_secret,
            'The secret must remain since the audit failure must roll back the reset.'
        );
        $this->assertNull($this->latestSettingsUpdate(PaymentGatewaySettings::class));
    }

    public function test_mark_production_checklist_reviewed_is_atomic_with_its_audit_record(): void
    {
        $settings = app(PaymentGatewaySettings::class);
        $settings->production_ready_at = null;
        $settings->save();

        $this->bindFailingAuditTrailService();

        $component = Livewire::test(PaymentGatewayPage::class);
        $this->callProtectedMethod($component->instance(), 'markProductionChecklistReviewed');

        $this->assertNull(app(PaymentGatewaySettings::class)->production_ready_at);
        $this->assertNull($this->latestSettingsUpdate(PaymentGatewaySettings::class));
    }

    public function test_persist_credential_fields_for_validation_is_atomic_with_its_audit_record(): void
    {
        $settings = app(PaymentGatewaySettings::class);
        $settings->razorpay_key_id = 'rzp_original_validate_id';
        $settings->save();

        $this->bindFailingAuditTrailService();

        $component = Livewire::test(PaymentGatewayPage::class)
            ->set('data.razorpay_enabled', true)
            ->set('data.razorpay_key_id', 'rzp_attempted_validate_id');

        $result = $this->callProtectedMethod($component->instance(), 'persistCredentialFieldsForValidation', 'razorpay');

        $this->assertFalse($result);
        $this->assertSame('rzp_original_validate_id', app(PaymentGatewaySettings::class)->razorpay_key_id);
        $this->assertNull($this->latestSettingsUpdate(PaymentGatewaySettings::class));
    }

    // ── 18. Existing audit viewer compatibility ──────────────────────────────

    public function test_existing_audit_viewer_can_still_display_the_resulting_event(): void
    {
        Livewire::test(PaymentBankAccountPage::class)
            ->fillForm([
                'enable_offline_payment' => true,
                'account_holder_name' => 'Holder',
                'bank_name' => 'Viewer Bank',
                'account_type' => 'savings',
                'account_number' => '123456789012',
                'account_number_confirm' => '123456789012',
                'ifsc_code' => 'ABCD0123456',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $activity = $this->latestSettingsUpdate(BankSettings::class);

        // Same shape every settings-audit viewer already reads.
        $this->assertSame('settings', $activity->log_name);
        $this->assertSame('settings_updated', $activity->event);
        $this->assertSame($this->superAdmin->id, $activity->causer_id);
        $this->assertNotNull($activity->actor_type);
        $this->assertArrayHasKey('changed', $activity->properties);
        $this->assertArrayHasKey('settings_class', $activity->properties);
    }

    // ── 19. No provider API call occurs ──────────────────────────────────────

    public function test_no_provider_api_call_occurs_during_atomic_save_or_rollback(): void
    {
        Http::fake();

        Livewire::test(PaymentGatewayPage::class)
            ->fillForm($this->gatewayFormBaseline(['razorpay_key_id' => 'rzp_http_check_id']))
            ->call('save')
            ->assertHasNoFormErrors();

        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function gatewayFormBaseline(array $overrides = []): array
    {
        return array_merge([
            'razorpay_enabled' => true,
            'razorpay_sandbox_mode' => true,
            'razorpay_key_id' => 'rzp_test_id',
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
        ], $overrides);
    }
}
