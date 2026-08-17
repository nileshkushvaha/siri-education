<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Concurrency;

use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StripeGatewayClient;
use App\Models\Currency;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WalletRecharge;
use App\Settings\BookingSettings;
use App\Settings\FeatureSettings;
use App\Settings\PaymentGatewaySettings;
use App\Wallet\Contracts\WalletRechargeServiceInterface;
use App\Wallet\Enums\WalletRechargeStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\Support\EstablishesRechargeMarket;

/**
 * Real multi-process races for wallet recharge (SRS §13.11/§13.33)
 * settlement — mirrors StripePaymentConcurrencyTest's shape. Reuses
 * the tests/Concurrency/run-op.php harness; the property under test is
 * that WalletRechargeSettlementService's locked-row
 * discipline (not this test, not Mockery) is what prevents a
 * double-credit.
 */
final class WalletRechargeConcurrencyTest extends ConcurrencyTestCase
{
    use EstablishesRechargeMarket;

    private const KEY_SECRET = 'test_concurrency_key_secret';

    private const WEBHOOK_SECRET = 'test_concurrency_webhook_secret';

    private function configureRazorpay(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_concurrency';
        $gateways->razorpay_key_secret = Crypt::encryptString(self::KEY_SECRET);
        $gateways->razorpay_webhook_secret = Crypt::encryptString(self::WEBHOOK_SECRET);
        $gateways->save();

        app(BookingSettings::class)->payment_provider = 'razorpay';
        app(BookingSettings::class)->save();
    }

    /** @return array{0: User, 1: WalletRecharge, 2: Payment} */
    private function initiatedRazorpayRecharge(int $amountMinor = 50000): array
    {
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $features = app(FeatureSettings::class);
        $features->wallet_enabled = true;
        $features->save();
        $this->configureRazorpay();

        // Recharge passes the same market gate as booking collection, so
        // the race needs a real active India/INR market to run in.
        $india = $this->establishRechargeMarket('IN', 'INR', provider: 'razorpay', numericCode: '356');

        $student = $this->attachStudentToMarket(
            User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]),
            $india,
        );

        // Called directly in THIS (parent) process only — child workers
        // launched by race() are separate PHP processes and never see
        // this mock; they bind Tests\Support\RazorpayConcurrencyFakeClient
        // themselves (see run-op.php).
        $orderId = 'order_concurrency_'.bin2hex(random_bytes(6));
        $gateway = Mockery::mock(RazorpayGatewayClient::class);
        $gateway->shouldReceive('createOrder')->once()->andReturn(['id' => $orderId]);
        $this->app->instance(RazorpayGatewayClient::class, $gateway);

        $checkout = app(WalletRechargeServiceInterface::class)->initiate($student, $amountMinor);

        $payment = Payment::query()->whereKey($checkout->paymentId)->sole();
        $recharge = WalletRecharge::query()->whereKey($payment->payable_id)->sole();

        return [$student, $recharge, $payment];
    }

    private function configureStripe(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->stripe_enabled = true;
        $gateways->stripe_publishable_key = 'pk_test_concurrency';
        $gateways->stripe_secret_key = Crypt::encryptString('sk_test_concurrency_secret');
        $gateways->stripe_webhook_secret = Crypt::encryptString(self::WEBHOOK_SECRET);
        $gateways->save();

        app(BookingSettings::class)->payment_provider = 'stripe';
        app(BookingSettings::class)->save();
    }

    /** @return array{0: User, 1: WalletRecharge, 2: Payment} */
    private function initiatedStripeRecharge(int $amountMinor = 50000): array
    {
        Currency::query()->firstOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar', 'symbol' => '$', 'numeric_code' => '840',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 2,
        ]);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $features = app(FeatureSettings::class);
        $features->wallet_enabled = true;
        $features->save();
        $this->configureStripe();

        // Wallet currency follows the student's own market, not the
        // platform default currency — a Stripe-routed US/USD market.
        $usa = $this->establishRechargeMarket('US', 'USD', provider: 'stripe', numericCode: '840');

        $student = $this->attachStudentToMarket(
            User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]),
            $usa,
        );

        $intentId = 'pi_concurrency_'.bin2hex(random_bytes(6));
        $gateway = Mockery::mock(StripeGatewayClient::class);
        $gateway->shouldReceive('createPaymentIntent')->once()->andReturn(['id' => $intentId, 'client_secret' => $intentId.'_secret']);
        $this->app->instance(StripeGatewayClient::class, $gateway);

        $checkout = app(WalletRechargeServiceInterface::class)->initiate($student, $amountMinor);

        $payment = Payment::query()->whereKey($checkout->paymentId)->sole();
        $recharge = WalletRecharge::query()->whereKey($payment->payable_id)->sole();

        return [$student, $recharge, $payment];
    }

    // ── 1. Duplicate webhook delivery ───────────────────────────────────

    public function test_duplicate_webhook_delivery_cannot_double_credit(): void
    {
        [, $recharge, $payment] = $this->initiatedRazorpayRecharge();

        $args = [
            'order_id' => $payment->provider_order_id,
            'payment_id' => 'pay_concurrency_'.bin2hex(random_bytes(6)),
            'amount_minor' => $recharge->amount_minor,
            'currency' => $recharge->currency_code,
            'reference' => $payment->idempotency_key,
            'webhook_secret' => self::WEBHOOK_SECRET,
        ];

        $this->race([
            ['wallet-recharge-webhook-succeed', $args],
            ['wallet-recharge-webhook-succeed', $args],
        ]);

        $recharge->refresh();
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->status);

        $wallet = Wallet::query()->whereKey($recharge->wallet_id)->sole();
        $this->assertSame($recharge->amount_minor, $wallet->balance_minor);
        $this->assertSame(
            1,
            WalletLedgerEntry::query()->where('wallet_id', $recharge->wallet_id)->where('entry_type', 'recharge_confirmed')->count(),
        );
    }

    // ── 2. Browser verification racing the webhook ──────────────────────

    public function test_browser_verification_racing_the_webhook_creates_exactly_one_credit(): void
    {
        [$student, $recharge, $payment] = $this->initiatedRazorpayRecharge();

        $paymentId = 'pay_concurrency_'.bin2hex(random_bytes(6));
        $signature = hash_hmac('sha256', "{$payment->provider_order_id}|{$paymentId}", self::KEY_SECRET);

        $this->race([
            ['wallet-recharge-verify', [
                'student_id' => $student->id,
                'order_id' => $payment->provider_order_id,
                'payment_id' => $paymentId,
                'signature' => $signature,
            ]],
            ['wallet-recharge-webhook-succeed', [
                'order_id' => $payment->provider_order_id,
                'payment_id' => $paymentId,
                'amount_minor' => $recharge->amount_minor,
                'currency' => $recharge->currency_code,
                'reference' => $payment->idempotency_key,
                'webhook_secret' => self::WEBHOOK_SECRET,
            ]],
        ]);

        $recharge->refresh();
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->status);

        $wallet = Wallet::query()->whereKey($recharge->wallet_id)->sole();
        $this->assertSame($recharge->amount_minor, $wallet->balance_minor);
        $this->assertSame(
            1,
            WalletLedgerEntry::query()->where('wallet_id', $recharge->wallet_id)->where('entry_type', 'recharge_confirmed')->count(),
        );
    }

    // ── 3. Concurrent recharge initiation never produces two provider orders for the same student action ──

    public function test_concurrent_initiation_for_the_same_student_produces_two_independent_attempts_never_a_shared_one(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $features = app(FeatureSettings::class);
        $features->wallet_enabled = true;
        $features->save();

        // Both child processes must find a collectable market, or each
        // initiate() fails eligibility and the race proves nothing. The
        // `fake` route keeps this test about the wallet-creation race
        // rather than about gateway credentials.
        $india = $this->establishRechargeMarket('IN', 'INR', provider: 'fake', numericCode: '356');

        $student = $this->attachStudentToMarket(
            User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]),
            $india,
        );

        $this->race([
            ['wallet-recharge-initiate', ['student_id' => $student->id, 'amount_minor' => 50000]],
            ['wallet-recharge-initiate', ['student_id' => $student->id, 'amount_minor' => 30000]],
        ]);

        // Each initiate() call is an independent, fully self-contained
        // attempt (no shared "in-flight recharge" concept) — both must
        // succeed with distinct idempotency keys, and exactly one wallet
        // must have been created (the unique(user_id, currency_id) index
        // is the actual guarantee, not this assertion).
        $this->assertSame(1, Wallet::query()->forUser($student->id)->count());
        $this->assertSame(2, WalletRecharge::query()->where('user_id', $student->id)->count());
        $this->assertSame(
            2,
            WalletRecharge::query()->where('user_id', $student->id)->distinct()->count('reference'),
        );
    }

    // ── 4. Stripe: duplicate webhook delivery ───────────────────────────

    public function test_stripe_duplicate_webhook_delivery_cannot_double_credit(): void
    {
        [, $recharge, $payment] = $this->initiatedStripeRecharge();

        $args = [
            'intent_id' => $payment->provider_order_id,
            'amount_minor' => $recharge->amount_minor,
            'currency' => strtolower($recharge->currency_code),
            'reference' => $payment->idempotency_key,
            'webhook_secret' => self::WEBHOOK_SECRET,
        ];

        $this->race([
            ['wallet-recharge-stripe-webhook-succeed', $args],
            ['wallet-recharge-stripe-webhook-succeed', $args],
        ]);

        $recharge->refresh();
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->status);

        $wallet = Wallet::query()->whereKey($recharge->wallet_id)->sole();
        $this->assertSame($recharge->amount_minor, $wallet->balance_minor);
        $this->assertSame(
            1,
            WalletLedgerEntry::query()->where('wallet_id', $recharge->wallet_id)->where('entry_type', 'recharge_confirmed')->count(),
        );
    }

    // ── 5. Stripe: webhook racing reconciliation ────────────────────────

    public function test_stripe_webhook_racing_reconciliation_creates_exactly_one_credit(): void
    {
        [, $recharge, $payment] = $this->initiatedStripeRecharge();

        // Due for a provider poll — a property of the ATTEMPT now.
        Payment::query()->whereKey($payment->id)->update([
            'created_at' => CarbonImmutable::now()->subMinutes(30),
            'last_synced_at' => null,
        ]);

        $this->race([
            ['wallet-recharge-stripe-webhook-succeed', [
                'intent_id' => $payment->provider_order_id,
                'amount_minor' => $recharge->amount_minor,
                'currency' => strtolower($recharge->currency_code),
                'reference' => $payment->idempotency_key,
                'webhook_secret' => self::WEBHOOK_SECRET,
            ]],
            ['wallet-recharge-reconcile', [
                'stripe_amount_received' => $recharge->amount_minor,
                'stripe_currency' => strtolower($recharge->currency_code),
            ]],
        ]);

        $recharge->refresh();
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->status);

        $wallet = Wallet::query()->whereKey($recharge->wallet_id)->sole();
        $this->assertSame($recharge->amount_minor, $wallet->balance_minor);
        $this->assertSame(
            1,
            WalletLedgerEntry::query()->where('wallet_id', $recharge->wallet_id)->where('entry_type', 'recharge_confirmed')->count(),
        );
    }
}
