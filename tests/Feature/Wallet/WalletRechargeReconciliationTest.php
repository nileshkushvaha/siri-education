<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StripeGatewayClient;
use App\Models\Currency;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WalletRecharge;
use App\Settings\BookingSettings;
use App\Settings\FeatureSettings;
use App\Settings\GeneralSettings;
use App\Settings\PaymentGatewaySettings;
use App\Wallet\Contracts\WalletRechargeServiceInterface;
use App\Wallet\Enums\WalletRechargeStatus;
use App\Wallet\Enums\WalletStatus;
use App\Wallet\Services\WalletRechargeReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WalletRechargeReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_SECRET = 'test_key_secret';

    protected function setUp(): void
    {
        parent::setUp();

        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);
        Currency::query()->firstOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar', 'symbol' => '$', 'numeric_code' => '840',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 2,
        ]);

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        app(FeatureSettings::class)->wallet_enabled = true;

        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString(self::KEY_SECRET);
        $gateways->stripe_enabled = true;
        $gateways->stripe_publishable_key = 'pk_test_wallet_recharge';
        $gateways->stripe_secret_key = Crypt::encryptString('sk_test_wallet_recharge_secret');
        $gateways->save();

        app(BookingSettings::class)->payment_provider = 'razorpay';
        app(BookingSettings::class)->save();
    }

    private function student(): User
    {
        return User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
    }

    private function mockRazorpayClient(): Mockery\MockInterface
    {
        $gateway = Mockery::mock(RazorpayGatewayClient::class);
        $this->app->instance(RazorpayGatewayClient::class, $gateway);

        return $gateway;
    }

    private function mockStripeClient(): Mockery\MockInterface
    {
        $gateway = Mockery::mock(StripeGatewayClient::class);
        $this->app->instance(StripeGatewayClient::class, $gateway);

        return $gateway;
    }

    private function initiatedRecharge(int $amountMinor = 50000): WalletRecharge
    {
        $gateway = $this->mockRazorpayClient();
        $orderId = 'order_'.uniqid();
        $gateway->shouldReceive('createOrder')->once()->andReturn(['id' => $orderId]);

        $student = $this->student();
        app(WalletRechargeServiceInterface::class)->initiate($student, $amountMinor);

        return WalletRecharge::query()->where('user_id', $student->id)->sole();
    }

    private function initiatedStripeRecharge(int $amountMinor = 50000): WalletRecharge
    {
        $gateway = $this->mockStripeClient();
        $intentId = 'pi_'.uniqid();
        $gateway->shouldReceive('createPaymentIntent')->once()->andReturn(['id' => $intentId, 'client_secret' => $intentId.'_secret']);

        $general = app(GeneralSettings::class);
        $general->default_currency = 'USD';
        $general->save();
        app(BookingSettings::class)->payment_provider = 'stripe';
        app(BookingSettings::class)->save();

        $student = $this->student();
        app(WalletRechargeServiceInterface::class)->initiate($student, $amountMinor);

        $general->default_currency = 'INR';
        $general->save();
        app(BookingSettings::class)->payment_provider = 'razorpay';
        app(BookingSettings::class)->save();

        return WalletRecharge::query()->where('user_id', $student->id)->sole();
    }

    private function makeDue(WalletRecharge $recharge): void
    {
        WalletRecharge::query()->whereKey($recharge->id)->update([
            'created_at' => CarbonImmutable::now()->subMinutes(30),
            'last_synced_at' => null,
        ]);
    }

    public function test_reconciliation_retries_and_credits_a_credit_pending_recharge(): void
    {
        $recharge = $this->initiatedRecharge();

        // Simulate a captured-but-uncredited state directly (the wallet was
        // closed at the moment settlement first tried to credit it).
        WalletRecharge::query()->whereKey($recharge->id)->update([
            'status' => WalletRechargeStatus::CreditPending,
            'provider_confirmed_at' => now(),
        ]);
        $this->makeDue($recharge->fresh());

        $examined = app(WalletRechargeReconciliationService::class)->reconcileDue();

        $this->assertSame(1, $examined);
        $recharge->refresh();
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->status);

        $wallet = Wallet::query()->whereKey($recharge->wallet_id)->sole();
        $this->assertSame(50000, $wallet->balance_minor);
    }

    public function test_reconciliation_retries_a_credit_failed_recharge_exactly_once_to_success(): void
    {
        $recharge = $this->initiatedRecharge();
        Wallet::query()->whereKey($recharge->wallet_id)->update(['status' => WalletStatus::Closed]);

        WalletRecharge::query()->whereKey($recharge->id)->update([
            'status' => WalletRechargeStatus::CreditFailed,
            'failure_code' => 'wallet_not_usable',
            'provider_confirmed_at' => now(),
        ]);
        $this->makeDue($recharge->fresh());

        // Still closed — the sweep must record the retry outcome without crashing.
        app(WalletRechargeReconciliationService::class)->reconcileDue();
        $this->assertSame(WalletRechargeStatus::CreditFailed, $recharge->fresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('wallet_id', $recharge->wallet_id)->count());

        // Reopen the wallet and sweep again — must now succeed exactly once.
        Wallet::query()->whereKey($recharge->wallet_id)->update(['status' => WalletStatus::Active]);
        WalletRecharge::query()->whereKey($recharge->id)->update(['last_synced_at' => null]);
        app(WalletRechargeReconciliationService::class)->reconcileDue();

        $recharge->refresh();
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->status);
        $this->assertSame(1, WalletLedgerEntry::query()->where('wallet_id', $recharge->wallet_id)->where('entry_type', 'recharge_confirmed')->count());
    }

    public function test_reconciliation_detects_a_captured_order_the_webhook_never_reported(): void
    {
        $recharge = $this->initiatedRecharge();
        $this->makeDue($recharge->fresh());

        $gateway = $this->mockRazorpayClient();
        $gateway->shouldReceive('fetchOrder')->once()->andReturn(['id' => $recharge->provider_order_id, 'status' => 'paid']);

        app(WalletRechargeReconciliationService::class)->reconcileDue();

        $recharge->refresh();
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->status);

        $wallet = Wallet::query()->whereKey($recharge->wallet_id)->sole();
        $this->assertSame(50000, $wallet->balance_minor);
    }

    public function test_reconciliation_never_guesses_success_for_a_still_pending_order(): void
    {
        $recharge = $this->initiatedRecharge();
        $this->makeDue($recharge->fresh());

        $gateway = $this->mockRazorpayClient();
        $gateway->shouldReceive('fetchOrder')->once()->andReturn(['id' => $recharge->provider_order_id, 'status' => 'created']);

        app(WalletRechargeReconciliationService::class)->reconcileDue();

        $recharge->refresh();
        $this->assertSame(WalletRechargeStatus::AwaitingConfirmation, $recharge->status);
        $this->assertNotNull($recharge->last_synced_at);
        $this->assertSame(0, WalletLedgerEntry::query()->where('wallet_id', $recharge->wallet_id)->count());
    }

    public function test_a_recently_synced_recharge_is_not_re_examined_before_the_cutoff(): void
    {
        $recharge = $this->initiatedRecharge();
        // Synced moments ago — not yet stale enough for a second look.
        WalletRecharge::query()->whereKey($recharge->id)->update(['last_synced_at' => now()]);

        $examined = app(WalletRechargeReconciliationService::class)->reconcileDue();

        $this->assertSame(0, $examined);
        $this->assertSame(WalletRechargeStatus::ProviderCreated, $recharge->fresh()->status);
    }

    public function test_a_succeeded_recharge_is_never_re_examined(): void
    {
        $recharge = $this->initiatedRecharge();
        $gateway = $this->mockRazorpayClient();
        $gateway->shouldReceive('fetchOrder')->once()->andReturn(['id' => $recharge->provider_order_id, 'status' => 'paid']);
        $this->makeDue($recharge->fresh());
        app(WalletRechargeReconciliationService::class)->reconcileDue();
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->fresh()->status);

        WalletRecharge::query()->whereKey($recharge->id)->update(['last_synced_at' => null]);
        $examined = app(WalletRechargeReconciliationService::class)->reconcileDue();

        $this->assertSame(0, $examined);
        $wallet = Wallet::query()->whereKey($recharge->wallet_id)->sole();
        $this->assertSame(50000, $wallet->balance_minor);
    }

    // ── Stripe ───────────────────────────────────────────────────────────

    public function test_stripe_reconciliation_settles_an_authoritative_succeeded_intent(): void
    {
        $recharge = $this->initiatedStripeRecharge();
        $this->makeDue($recharge->fresh());

        $gateway = $this->mockStripeClient();
        $gateway->shouldReceive('retrievePaymentIntent')->once()->andReturn([
            'id' => $recharge->provider_order_id,
            'status' => 'succeeded',
            'amount_received' => 50000,
            'currency' => 'usd',
        ]);

        app(WalletRechargeReconciliationService::class)->reconcileDue();

        $recharge->refresh();
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->status);

        $wallet = Wallet::query()->whereKey($recharge->wallet_id)->sole();
        $this->assertSame(50000, $wallet->balance_minor);
    }

    public function test_stripe_reconciliation_leaves_a_processing_intent_awaiting_confirmation(): void
    {
        $recharge = $this->initiatedStripeRecharge();
        $this->makeDue($recharge->fresh());

        $gateway = $this->mockStripeClient();
        $gateway->shouldReceive('retrievePaymentIntent')->once()->andReturn([
            'id' => $recharge->provider_order_id,
            'status' => 'processing',
        ]);

        app(WalletRechargeReconciliationService::class)->reconcileDue();

        $recharge->refresh();
        $this->assertSame(WalletRechargeStatus::AwaitingConfirmation, $recharge->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('wallet_id', $recharge->wallet_id)->count());
    }

    public function test_stripe_reconciliation_marks_terminal_failure_only_from_an_authoritative_canceled_intent(): void
    {
        $recharge = $this->initiatedStripeRecharge();
        $this->makeDue($recharge->fresh());

        $gateway = $this->mockStripeClient();
        $gateway->shouldReceive('retrievePaymentIntent')->once()->andReturn([
            'id' => $recharge->provider_order_id,
            'status' => 'canceled',
        ]);

        app(WalletRechargeReconciliationService::class)->reconcileDue();

        $recharge->refresh();
        $this->assertSame(WalletRechargeStatus::Failed, $recharge->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('wallet_id', $recharge->wallet_id)->count());
    }

    public function test_stripe_reconciliation_amount_mismatch_never_settles(): void
    {
        $recharge = $this->initiatedStripeRecharge(amountMinor: 50000);
        $this->makeDue($recharge->fresh());

        $gateway = $this->mockStripeClient();
        $gateway->shouldReceive('retrievePaymentIntent')->once()->andReturn([
            'id' => $recharge->provider_order_id,
            'status' => 'succeeded',
            'amount_received' => 1,
            'currency' => 'usd',
        ]);

        app(WalletRechargeReconciliationService::class)->reconcileDue();

        $this->assertSame(WalletRechargeStatus::ProviderCreated, $recharge->fresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('wallet_id', $recharge->wallet_id)->count());
    }

    public function test_stripe_credit_failed_reconciliation_retry_remains_idempotent(): void
    {
        $recharge = $this->initiatedStripeRecharge();
        Wallet::query()->whereKey($recharge->wallet_id)->update(['status' => WalletStatus::Closed]);

        WalletRecharge::query()->whereKey($recharge->id)->update([
            'status' => WalletRechargeStatus::CreditFailed,
            'failure_code' => 'wallet_not_usable',
            'provider_confirmed_at' => now(),
        ]);
        $this->makeDue($recharge->fresh());

        app(WalletRechargeReconciliationService::class)->reconcileDue();
        $this->assertSame(WalletRechargeStatus::CreditFailed, $recharge->fresh()->status);

        Wallet::query()->whereKey($recharge->wallet_id)->update(['status' => WalletStatus::Active]);
        WalletRecharge::query()->whereKey($recharge->id)->update(['last_synced_at' => null]);
        app(WalletRechargeReconciliationService::class)->reconcileDue();

        $recharge->refresh();
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->status);
        $this->assertSame(1, WalletLedgerEntry::query()->where('wallet_id', $recharge->wallet_id)->where('entry_type', 'recharge_confirmed')->count());
    }
}
