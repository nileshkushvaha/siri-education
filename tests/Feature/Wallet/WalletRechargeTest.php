<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Exceptions\GatewayRequestException;
use App\Enums\StudentStatus;
use App\Exceptions\Student\StudentActionNotAvailableException;
use App\Livewire\Frontend\Student\WalletOverview;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WalletRecharge;
use App\Notifications\Wallet\WalletRechargeSucceededNotification;
use App\Reporting\Repositories\WalletFinancialReportRepository;
use App\Settings\BookingSettings;
use App\Settings\FeatureSettings;
use App\Settings\PaymentGatewaySettings;
use App\Settings\WalletSettings;
use App\Wallet\Contracts\WalletRechargeServiceInterface;
use App\Wallet\DTOs\WalletRechargeProviderEvent;
use App\Wallet\Enums\WalletRechargeProviderEventType;
use App\Wallet\Enums\WalletRechargeStatus;
use App\Wallet\Enums\WalletStatus;
use App\Wallet\Exceptions\WalletException;
use App\Wallet\Services\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WalletRechargeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        app(FeatureSettings::class)->wallet_enabled = true;
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function student(): User
    {
        return User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
    }

    private function service(): WalletRechargeServiceInterface
    {
        return app(WalletRechargeServiceInterface::class);
    }

    private function configureRazorpay(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString('test_key_secret');
        $gateways->razorpay_webhook_secret = Crypt::encryptString('test_webhook_secret');
        $gateways->save();

        app(BookingSettings::class)->payment_provider = 'razorpay';
        app(BookingSettings::class)->save();
    }

    private function fakeRazorpayOrderApi(string $orderId = 'order_TEST123'): void
    {
        $gateway = Mockery::mock(RazorpayGatewayClient::class);
        $gateway->shouldReceive('createOrder')->andReturn(['id' => $orderId]);
        $this->app->instance(RazorpayGatewayClient::class, $gateway);
    }

    /** A fresh recharge on the default (fake-provider) path, at ProviderCreated. */
    private function pendingFakeRecharge(User $student, int $amountMinor = 50000): WalletRecharge
    {
        $checkout = $this->service()->initiate($student, $amountMinor);

        return WalletRecharge::query()->where('idempotency_key', $checkout->reference)->sole();
    }

    private function captureEvent(WalletRecharge $recharge, ?int $amountMinor = null, ?string $currency = null, ?string $provider = null): WalletRechargeProviderEvent
    {
        return new WalletRechargeProviderEvent(
            provider: $provider ?? $recharge->provider,
            reference: $recharge->idempotency_key,
            providerOrderId: $recharge->provider_order_id,
            providerPaymentId: 'fake_payment_'.$recharge->id,
            amountMinor: $amountMinor ?? $recharge->amount_minor,
            currencyCode: $currency ?? $recharge->currency_code,
            type: WalletRechargeProviderEventType::Captured,
        );
    }

    // ── Initiation ───────────────────────────────────────────────────────

    public function test_eligible_student_initiates_a_recharge(): void
    {
        $student = $this->student();

        $checkout = $this->service()->initiate($student, 50000);

        $this->assertSame('fake', $checkout->provider);
        $this->assertSame(50000, $checkout->amountMinor);
        $this->assertSame('INR', $checkout->currencyCode);

        $recharge = WalletRecharge::query()->where('idempotency_key', $checkout->reference)->sole();
        $this->assertSame(WalletRechargeStatus::ProviderCreated, $recharge->status);
        $this->assertSame($student->id, $recharge->user_id);
    }

    public function test_wallet_is_created_before_the_non_null_recharge_row_when_no_wallet_exists(): void
    {
        $student = $this->student();
        $this->assertNull(Wallet::query()->forUser($student->id)->first());

        $checkout = $this->service()->initiate($student, 50000);

        $wallet = Wallet::query()->forUser($student->id)->sole();
        $recharge = WalletRecharge::query()->where('idempotency_key', $checkout->reference)->sole();

        $this->assertNotNull($recharge->wallet_id);
        $this->assertSame($wallet->id, $recharge->wallet_id);
        $this->assertSame($student->id, $recharge->user_id);
    }

    public function test_a_restricted_student_lifecycle_rejects_initiation(): void
    {
        $student = $this->student();
        UserProfile::query()->where('user_id', $student->id)->update(['student_status' => StudentStatus::Suspended]);

        $this->expectException(StudentActionNotAvailableException::class);

        $this->service()->initiate($student, 50000);
    }

    public function test_wallet_ui_renders_restricted_student_error_instead_of_throwing_a_403(): void
    {
        $student = $this->student();
        UserProfile::query()->where('user_id', $student->id)->update(['student_status' => StudentStatus::Suspended]);

        Livewire::actingAs($student)
            ->test(WalletOverview::class)
            ->set('rechargeAmount', '500')
            ->call('initiateRecharge')
            ->assertSet('rechargeBanner', StudentActionNotAvailableException::make()->getMessage())
            ->assertSee('Your account is not available for this action. Please contact support.');

        $this->assertDatabaseCount('wallet_recharges', 0);
    }

    public function test_wallet_feature_disabled_rejects_initiation(): void
    {
        app(FeatureSettings::class)->wallet_enabled = false;
        $student = $this->student();

        $this->expectException(WalletException::class);

        $this->service()->initiate($student, 50000);
    }

    public function test_frozen_wallet_rejects_new_initiation(): void
    {
        $student = $this->student();
        $wallet = Wallet::query()->create([
            'user_id' => $student->id,
            'currency_id' => Currency::query()->where('code', 'INR')->value('id'),
            'currency_code' => 'INR',
            'status' => WalletStatus::Frozen,
        ]);

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('frozen');

        $this->service()->initiate($student, 50000);
    }

    public function test_closed_wallet_rejects_initiation(): void
    {
        $student = $this->student();
        Wallet::query()->create([
            'user_id' => $student->id,
            'currency_id' => Currency::query()->where('code', 'INR')->value('id'),
            'currency_code' => 'INR',
            'status' => WalletStatus::Closed,
        ]);

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('closed');

        $this->service()->initiate($student, 50000);
    }

    public function test_zero_amount_is_rejected(): void
    {
        $this->expectException(WalletException::class);

        $this->service()->initiate($this->student(), 0);
    }

    public function test_negative_amount_is_rejected(): void
    {
        $this->expectException(WalletException::class);

        $this->service()->initiate($this->student(), -100);
    }

    public function test_below_minimum_amount_is_rejected(): void
    {
        app(WalletSettings::class)->minimum_recharge_amount = 100.0;
        app(WalletSettings::class)->save();

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('minimum');

        $this->service()->initiate($this->student(), 5000); // 50.00 < 100.00 minimum
    }

    public function test_above_maximum_amount_is_rejected(): void
    {
        app(WalletSettings::class)->maximum_recharge_amount = 50000.0;
        app(WalletSettings::class)->save();

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('maximum');

        $this->service()->initiate($this->student(), 6_000_000); // 60,000.00 > 50,000.00 maximum
    }

    public function test_inactive_currency_rejects_initiation(): void
    {
        $student = $this->student();
        Currency::query()->where('code', 'INR')->update(['status' => 'inactive']);

        $this->expectException(WalletException::class);

        $this->service()->initiate($student, 50000);
    }

    public function test_provider_capability_false_rejects_initiation(): void
    {
        $this->configureRazorpay();
        // Razorpay only supports INR and declares supportsWalletRecharge=true;
        // routing this student to a country whose currency Razorpay does not
        // support proves the capability/currency gate, not just "no provider".
        Currency::query()->firstOrCreate(['code' => 'GBP'], [
            'name' => 'British Pound', 'symbol' => '£', 'numeric_code' => '826',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 2,
        ]);
        $country = Country::factory()->create(['default_currency_id' => Currency::query()->where('code', 'GBP')->value('id')]);
        $student = $this->student();
        UserProfile::query()->where('user_id', $student->id)->update(['country_id' => $country->id]);

        $this->expectException(WalletException::class);

        $this->service()->initiate($student, 5000);
    }

    public function test_provider_creation_failure_creates_no_ledger_entry_and_marks_the_attempt_failed(): void
    {
        $this->configureRazorpay();
        $gateway = Mockery::mock(RazorpayGatewayClient::class);
        $gateway->shouldReceive('createOrder')->andThrow(new GatewayRequestException('network error'));
        $this->app->instance(RazorpayGatewayClient::class, $gateway);

        $student = $this->student();

        try {
            $this->service()->initiate($student, 50000);
            $this->fail('Expected a WalletException.');
        } catch (WalletException) {
            // expected
        }

        $recharge = WalletRecharge::query()->where('user_id', $student->id)->sole();
        $this->assertSame(WalletRechargeStatus::Failed, $recharge->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
    }

    public function test_fake_provider_recharge_is_available_in_testing(): void
    {
        $checkout = $this->service()->initiate($this->student(), 50000);

        $this->assertSame('fake', $checkout->provider);
    }

    // ── Settlement ───────────────────────────────────────────────────────

    public function test_valid_authoritative_capture_credits_exact_minor_units(): void
    {
        Notification::fake();
        $student = $this->student();
        $recharge = $this->pendingFakeRecharge($student, 50000);

        $result = $this->service()->processProviderEvent($this->captureEvent($recharge));

        $this->assertTrue($result->credited);
        $this->assertSame(WalletRechargeStatus::Succeeded, $result->recharge->status);

        $wallet = Wallet::query()->forUser($student->id)->sole();
        $this->assertSame(50000, $wallet->balance_minor);

        Notification::assertSentTo($student, WalletRechargeSucceededNotification::class);
    }

    public function test_exactly_one_ledger_entry_with_correct_source_linkage(): void
    {
        $student = $this->student();
        $recharge = $this->pendingFakeRecharge($student, 50000);

        $this->service()->processProviderEvent($this->captureEvent($recharge));

        $entry = WalletLedgerEntry::query()->where('user_id', $student->id)->where('entry_type', 'recharge_confirmed')->sole();

        $this->assertSame(50000, $entry->amount_minor);
        $this->assertSame(WalletRecharge::class, $entry->source_type);
        $this->assertSame($recharge->id, $entry->source_id);
    }

    public function test_provider_amount_mismatch_never_credits(): void
    {
        $student = $this->student();
        $recharge = $this->pendingFakeRecharge($student, 50000);

        $this->expectException(WalletException::class);

        try {
            $this->service()->processProviderEvent($this->captureEvent($recharge, amountMinor: 1));
        } finally {
            $this->assertSame(WalletRechargeStatus::ProviderCreated, $recharge->fresh()->status);
            $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
        }
    }

    public function test_provider_currency_mismatch_never_credits(): void
    {
        $student = $this->student();
        $recharge = $this->pendingFakeRecharge($student, 50000);

        $this->expectException(WalletException::class);

        try {
            $this->service()->processProviderEvent($this->captureEvent($recharge, currency: 'USD'));
        } finally {
            $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
        }
    }

    public function test_provider_mismatch_never_credits(): void
    {
        $student = $this->student();
        $recharge = $this->pendingFakeRecharge($student, 50000);

        $this->expectException(WalletException::class);

        try {
            $this->service()->processProviderEvent($this->captureEvent($recharge, provider: 'razorpay'));
        } finally {
            $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
        }
    }

    public function test_a_processing_event_never_credits(): void
    {
        $student = $this->student();
        $recharge = $this->pendingFakeRecharge($student, 50000);

        $result = $this->service()->processProviderEvent(new WalletRechargeProviderEvent(
            provider: 'fake',
            reference: $recharge->idempotency_key,
            providerOrderId: $recharge->provider_order_id,
            providerPaymentId: null,
            amountMinor: $recharge->amount_minor,
            currencyCode: $recharge->currency_code,
            type: WalletRechargeProviderEventType::Processing,
        ));

        $this->assertFalse($result->credited);
        $this->assertTrue($result->ignored);
        $this->assertSame(WalletRechargeStatus::ProviderCreated, $recharge->fresh()->status);
    }

    public function test_terminal_provider_failure_never_credits(): void
    {
        $student = $this->student();
        $recharge = $this->pendingFakeRecharge($student, 50000);

        $result = $this->service()->processProviderEvent(new WalletRechargeProviderEvent(
            provider: 'fake',
            reference: $recharge->idempotency_key,
            providerOrderId: $recharge->provider_order_id,
            providerPaymentId: null,
            amountMinor: $recharge->amount_minor,
            currencyCode: $recharge->currency_code,
            type: WalletRechargeProviderEventType::Failed,
            reason: 'card declined',
        ));

        $this->assertFalse($result->credited);
        $this->assertSame(WalletRechargeStatus::Failed, $result->recharge->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
    }

    public function test_duplicate_event_delivery_returns_ignored_and_does_not_double_credit(): void
    {
        $student = $this->student();
        $recharge = $this->pendingFakeRecharge($student, 50000);
        $event = $this->captureEvent($recharge);

        $first = $this->service()->processProviderEvent($event);
        $second = $this->service()->processProviderEvent($event);

        $this->assertTrue($first->credited);
        $this->assertFalse($second->credited);
        $this->assertTrue($second->ignored);

        $wallet = Wallet::query()->forUser($student->id)->sole();
        $this->assertSame(50000, $wallet->balance_minor);
        $this->assertSame(1, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
    }

    public function test_a_gateway_paid_recharge_row_cannot_be_processed_twice_via_retry(): void
    {
        $student = $this->student();
        $recharge = $this->pendingFakeRecharge($student, 50000);
        $this->service()->processProviderEvent($this->captureEvent($recharge));

        $this->expectException(WalletException::class);

        $this->service()->retryPendingCredit($recharge->fresh());
    }

    public function test_a_failure_after_the_ledger_credit_rolls_back_the_credit_and_the_recharge_status(): void
    {
        $student = $this->student();
        $recharge = $this->pendingFakeRecharge($student, 50000);

        // Force the wallet to close between order creation and settlement,
        // which WalletLedgerService::credit() rejects — proving the whole
        // credit_pending -> credit transaction rolls back to CreditFailed
        // rather than a partial state.
        Wallet::query()->whereKey($recharge->wallet_id)->update(['status' => WalletStatus::Closed]);

        $result = $this->service()->processProviderEvent($this->captureEvent($recharge));

        $this->assertFalse($result->credited);
        $this->assertSame(WalletRechargeStatus::CreditFailed, $result->recharge->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());

        // The provider capture itself is durably recorded, never discarded.
        $this->assertNotNull($result->recharge->provider_confirmed_at);
    }

    public function test_retry_after_a_credit_failure_succeeds_exactly_once(): void
    {
        $student = $this->student();
        $recharge = $this->pendingFakeRecharge($student, 50000);
        Wallet::query()->whereKey($recharge->wallet_id)->update(['status' => WalletStatus::Closed]);
        $this->service()->processProviderEvent($this->captureEvent($recharge));

        Wallet::query()->whereKey($recharge->wallet_id)->update(['status' => WalletStatus::Active]);
        $retried = $this->service()->retryPendingCredit($recharge->fresh());

        $this->assertSame(WalletRechargeStatus::Succeeded, $retried->status);
        $wallet = Wallet::query()->forUser($student->id)->sole();
        $this->assertSame(50000, $wallet->balance_minor);
        $this->assertSame(1, WalletLedgerEntry::query()->where('user_id', $student->id)->where('entry_type', 'recharge_confirmed')->count());

        // A further retry attempt is a clean no-op, not a second credit.
        $this->expectException(WalletException::class);
        $this->service()->retryPendingCredit($retried->fresh());
    }

    public function test_frozen_after_initiation_wallet_still_accepts_confirmed_credit(): void
    {
        $student = $this->student();
        $recharge = $this->pendingFakeRecharge($student, 50000);
        Wallet::query()->whereKey($recharge->wallet_id)->update(['status' => WalletStatus::Frozen]);

        $result = $this->service()->processProviderEvent($this->captureEvent($recharge));

        $this->assertTrue($result->credited);
        $wallet = Wallet::query()->forUser($student->id)->sole();
        $this->assertSame(50000, $wallet->balance_minor);
        $this->assertSame(WalletStatus::Frozen, $wallet->status);
    }

    // ── Razorpay-specific ────────────────────────────────────────────────

    public function test_razorpay_recharge_capability_is_enabled(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi();
        $student = $this->student();

        $checkout = $this->service()->initiate($student, 50000);

        $this->assertSame('razorpay', $checkout->provider);
        $this->assertArrayHasKey('key_id', $checkout->checkoutPayload);
    }

    public function test_razorpay_signature_verification_and_internal_reference_matching(): void
    {
        Notification::fake();
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_SIG1');
        $student = $this->student();

        $this->service()->initiate($student, 50000);
        $recharge = WalletRecharge::query()->where('user_id', $student->id)->sole();

        $paymentId = 'pay_SIG1';
        $signature = hash_hmac('sha256', 'order_SIG1|'.$paymentId, 'test_key_secret');

        $settled = $this->service()->verifyRazorpayCheckout($student, 'order_SIG1', $paymentId, $signature);

        $this->assertSame(WalletRechargeStatus::Succeeded, $settled->status);
        $wallet = Wallet::query()->forUser($student->id)->sole();
        $this->assertSame(50000, $wallet->balance_minor);
    }

    public function test_razorpay_invalid_signature_never_credits(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_BADSIG');
        $student = $this->student();
        $this->service()->initiate($student, 50000);

        $this->expectException(WalletException::class);

        try {
            $this->service()->verifyRazorpayCheckout($student, 'order_BADSIG', 'pay_BADSIG', 'not-a-real-signature');
        } finally {
            $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
        }
    }

    public function test_a_students_own_wallet_recharge_cannot_be_verified_by_another_student(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_OWNER1');
        $owner = $this->student();
        $intruder = $this->student();
        $this->service()->initiate($owner, 50000);

        $paymentId = 'pay_OWNER1';
        $signature = hash_hmac('sha256', 'order_OWNER1|'.$paymentId, 'test_key_secret');

        $this->expectException(WalletException::class);

        try {
            $this->service()->verifyRazorpayCheckout($intruder, 'order_OWNER1', $paymentId, $signature);
        } finally {
            $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $owner->id)->count());
        }
    }

    // ── Reporting ────────────────────────────────────────────────────────

    public function test_successful_recharge_appears_in_the_wallet_statement(): void
    {
        $student = $this->student();
        $recharge = $this->pendingFakeRecharge($student, 50000);
        $this->service()->processProviderEvent($this->captureEvent($recharge));

        $wallet = Wallet::query()->forUser($student->id)->sole();
        $statement = app(WalletLedgerService::class)->statement($wallet, 10);

        $this->assertTrue($statement->getCollection()->contains(fn ($entry) => $entry->entry_type->value === 'recharge_confirmed'));
    }

    public function test_pending_and_failed_recharges_are_visible_operationally_but_not_in_the_ledger(): void
    {
        $student = $this->student();
        $pending = $this->pendingFakeRecharge($student, 50000);
        $failed = $this->pendingFakeRecharge($student, 20000);
        $this->service()->markProviderFailure('fake', $failed->idempotency_key, 'test_failure');

        $breakdown = app(WalletFinancialReportRepository::class)->rechargeAttemptStatusBreakdown();

        $this->assertSame(1, $breakdown['provider_created'] ?? 0);
        $this->assertSame(1, $breakdown['failed'] ?? 0);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
    }
}
