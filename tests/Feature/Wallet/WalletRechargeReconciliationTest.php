<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StripeGatewayClient;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WalletRecharge;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Services\PaymentService;
use App\Settings\BookingSettings;
use App\Settings\FeatureSettings;
use App\Settings\PaymentGatewaySettings;
use App\Wallet\Enums\WalletRechargeStatus;
use App\Wallet\Enums\WalletStatus;
use App\Wallet\Services\WalletRechargeReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\Support\EstablishesRechargeMarket;
use Tests\Support\InitiatesWalletRecharges;
use Tests\TestCase;

class WalletRechargeReconciliationTest extends TestCase
{
    use EstablishesRechargeMarket;
    use InitiatesWalletRecharges;
    use RefreshDatabase;

    private Country $india;

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

        // Recharge passes the same market gate as booking collection, so
        // an active India/INR market routed at Razorpay is a
        // precondition for every case in this file.
        $this->india = $this->establishRechargeMarket('IN', 'INR', provider: 'razorpay', numericCode: '356');
    }

    private function student(): User
    {
        return $this->attachStudentToMarket(
            User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]),
            $this->india,
        );
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

    /** @return array{0: WalletRecharge, 1: Payment} */
    private function initiatedRecharge(int $amountMinor = 50000): array
    {
        $gateway = $this->mockRazorpayClient();
        $orderId = 'order_'.uniqid();
        $gateway->shouldReceive('createOrder')->once()->andReturn(['id' => $orderId]);

        return $this->initiateRecharge($this->student(), $amountMinor);
    }

    /**
     * A Stripe recharge belongs to a student in a Stripe-routed MARKET,
     * not to a temporary swap of the platform default currency. Wallet
     * currency follows the student's billing country and the provider
     * follows that country's payment_routing, so this seeds a real
     * US/USD market at Stripe and puts the student in it — the India
     * market other cases in this file use is untouched.
     */
    /** @return array{0: WalletRecharge, 1: Payment} */
    private function initiatedStripeRecharge(int $amountMinor = 50000): array
    {
        $gateway = $this->mockStripeClient();
        $intentId = 'pi_'.uniqid();
        $gateway->shouldReceive('createPaymentIntent')->once()->andReturn(['id' => $intentId, 'client_secret' => $intentId.'_secret']);

        $usa = $this->establishRechargeMarket('US', 'USD', provider: 'stripe', numericCode: '840');

        $student = $this->attachStudentToMarket(
            User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]),
            $usa,
        );

        return $this->initiateRecharge($student, $amountMinor);
    }

    /**
     * Reconciliation now sweeps the recharge slice of the generic
     * `payments` ledger, so "due" is a property of the ATTEMPT — the
     * recharge no longer carries a last_synced_at of its own, because
     * only one component polls the provider.
     */
    private function makeDue(Payment $payment): void
    {
        Payment::query()->whereKey($payment->id)->update([
            'created_at' => CarbonImmutable::now()->subMinutes(30),
            'last_synced_at' => null,
        ]);
    }

    public function test_reconciliation_retries_and_credits_a_credit_pending_recharge(): void
    {
        [$recharge, $payment] = $this->initiatedRecharge();

        // A captured-but-uncredited state: the money is collected
        // (Payment = Paid) and only the ledger credit is outstanding.
        // Recovery from here needs no provider call at all.
        app(PaymentService::class)->transition($payment, PaymentStatus::Paid);
        WalletRecharge::query()->whereKey($recharge->id)->update([
            'status' => WalletRechargeStatus::CreditPending,
        ]);

        $examined = app(WalletRechargeReconciliationService::class)->reconcileDue();

        $this->assertSame(1, $examined);
        $recharge->refresh();
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->status);

        $wallet = Wallet::query()->whereKey($recharge->wallet_id)->sole();
        $this->assertSame(50000, $wallet->balance_minor);
    }

    public function test_reconciliation_retries_a_credit_failed_recharge_exactly_once_to_success(): void
    {
        [$recharge, $payment] = $this->initiatedRecharge();
        Wallet::query()->whereKey($recharge->wallet_id)->update(['status' => WalletStatus::Closed]);

        // The real two-phase state: the money IS collected, so the
        // Payment is Paid; only the wallet credit is outstanding.
        app(PaymentService::class)->transition($payment, PaymentStatus::Paid);
        WalletRecharge::query()->whereKey($recharge->id)->update([
            'status' => WalletRechargeStatus::CreditFailed,
            'failure_code' => 'wallet_not_usable',
        ]);

        // Still closed — the sweep must record the retry outcome without crashing.
        app(WalletRechargeReconciliationService::class)->reconcileDue();
        $this->assertSame(WalletRechargeStatus::CreditFailed, $recharge->fresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('wallet_id', $recharge->wallet_id)->count());

        // Reopen the wallet and sweep again — must now succeed exactly once.
        Wallet::query()->whereKey($recharge->wallet_id)->update(['status' => WalletStatus::Active]);
        app(WalletRechargeReconciliationService::class)->reconcileDue();

        $recharge->refresh();
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->status);
        $this->assertSame(1, WalletLedgerEntry::query()->where('wallet_id', $recharge->wallet_id)->where('entry_type', 'recharge_confirmed')->count());
    }

    public function test_reconciliation_detects_a_captured_order_the_webhook_never_reported(): void
    {
        [$recharge, $payment] = $this->initiatedRecharge();
        $this->makeDue($payment->fresh());

        $gateway = $this->mockRazorpayClient();
        $gateway->shouldReceive('fetchOrder')->zeroOrMoreTimes()->andReturn(['id' => $payment->provider_order_id, 'status' => 'paid', 'amount' => 50000, 'currency' => 'INR']);

        app(WalletRechargeReconciliationService::class)->reconcileDue();

        $recharge->refresh();
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->status);

        $wallet = Wallet::query()->whereKey($recharge->wallet_id)->sole();
        $this->assertSame(50000, $wallet->balance_minor);
    }

    public function test_reconciliation_never_guesses_success_for_a_still_pending_order(): void
    {
        [$recharge, $payment] = $this->initiatedRecharge();
        $this->makeDue($payment->fresh());

        $gateway = $this->mockRazorpayClient();
        $gateway->shouldReceive('fetchOrder')->zeroOrMoreTimes()->andReturn(['id' => $payment->provider_order_id, 'status' => 'created']);

        app(WalletRechargeReconciliationService::class)->reconcileDue();

        $recharge->refresh();
        $this->assertSame(WalletRechargeStatus::Requested, $recharge->status);
        $this->assertNotNull($payment->fresh()->last_synced_at);
        $this->assertSame(0, WalletLedgerEntry::query()->where('wallet_id', $recharge->wallet_id)->count());
    }

    public function test_a_recently_synced_recharge_is_not_re_examined_before_the_cutoff(): void
    {
        [$recharge, $payment] = $this->initiatedRecharge();
        // Synced moments ago — not yet stale enough for a second look.
        // Staleness is a property of the ATTEMPT now: only one component
        // polls the provider, so only one row records when it last did.
        Payment::query()->whereKey($payment->id)->update(['last_synced_at' => now()]);

        $examined = app(WalletRechargeReconciliationService::class)->reconcileDue();

        $this->assertSame(0, $examined);
        $this->assertSame(WalletRechargeStatus::Requested, $recharge->fresh()->status);
    }

    /**
     * The Razorpay counterpart of the existing Stripe amount-mismatch
     * case. Reconciliation used to pass the LOCAL row's own
     * amount/currency into the settlement path, so the mismatch guards
     * compared the row against itself and any order Razorpay called
     * "paid" settled — whatever it was actually paid for. The order body
     * carries both fields; they are now the ones checked.
     */
    public function test_reconciliation_refuses_a_paid_order_whose_amount_disagrees(): void
    {
        [$recharge, $payment] = $this->initiatedRecharge();
        $this->makeDue($payment->fresh());

        $gateway = $this->mockRazorpayClient();
        $gateway->shouldReceive('fetchOrder')->zeroOrMoreTimes()->andReturn([
            'id' => $payment->provider_order_id,
            'status' => 'paid',
            'amount' => 10000, // Razorpay collected ₹100, not the ₹500 on record.
            'currency' => 'INR',
        ]);

        app(WalletRechargeReconciliationService::class)->reconcileDue();

        $this->assertNotSame(WalletRechargeStatus::Succeeded, $recharge->fresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('wallet_id', $recharge->wallet_id)->count());
        $this->assertSame(0, Wallet::query()->whereKey($recharge->wallet_id)->sole()->balance_minor);
    }

    /** Same rule for the currency: an authentic "paid" order in the wrong currency is not this recharge's money. */
    public function test_reconciliation_refuses_a_paid_order_whose_currency_disagrees(): void
    {
        [$recharge, $payment] = $this->initiatedRecharge();
        $this->makeDue($payment->fresh());

        $gateway = $this->mockRazorpayClient();
        $gateway->shouldReceive('fetchOrder')->zeroOrMoreTimes()->andReturn([
            'id' => $payment->provider_order_id,
            'status' => 'paid',
            'amount' => 50000,
            'currency' => 'USD', // Right number, wrong money.
        ]);

        app(WalletRechargeReconciliationService::class)->reconcileDue();

        $this->assertNotSame(WalletRechargeStatus::Succeeded, $recharge->fresh()->status);
        $this->assertSame(0, Wallet::query()->whereKey($recharge->wallet_id)->sole()->balance_minor);
    }

    /** An order body missing amount/currency proves nothing — fail closed rather than assume. */
    public function test_reconciliation_refuses_a_paid_order_that_reports_no_amount(): void
    {
        [$recharge, $payment] = $this->initiatedRecharge();
        $this->makeDue($payment->fresh());

        $gateway = $this->mockRazorpayClient();
        $gateway->shouldReceive('fetchOrder')->zeroOrMoreTimes()->andReturn([
            'id' => $payment->provider_order_id,
            'status' => 'paid',
        ]);

        app(WalletRechargeReconciliationService::class)->reconcileDue();

        $this->assertNotSame(WalletRechargeStatus::Succeeded, $recharge->fresh()->status);
        $this->assertSame(0, Wallet::query()->whereKey($recharge->wallet_id)->sole()->balance_minor);
    }

    public function test_a_succeeded_recharge_is_never_re_examined(): void
    {
        [$recharge, $payment] = $this->initiatedRecharge();
        $gateway = $this->mockRazorpayClient();
        $gateway->shouldReceive('fetchOrder')->zeroOrMoreTimes()->andReturn(['id' => $payment->provider_order_id, 'status' => 'paid', 'amount' => 50000, 'currency' => 'INR']);
        $this->makeDue($payment->fresh());
        app(WalletRechargeReconciliationService::class)->reconcileDue();
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->fresh()->status);

        Payment::query()->whereKey($payment->id)->update(['last_synced_at' => null]);
        $examined = app(WalletRechargeReconciliationService::class)->reconcileDue();

        $this->assertSame(0, $examined);
        $wallet = Wallet::query()->whereKey($recharge->wallet_id)->sole();
        $this->assertSame(50000, $wallet->balance_minor);
    }

    // ── Stripe ───────────────────────────────────────────────────────────

    public function test_stripe_reconciliation_settles_an_authoritative_succeeded_intent(): void
    {
        [$recharge, $payment] = $this->initiatedStripeRecharge();
        $this->makeDue($payment->fresh());

        $gateway = $this->mockStripeClient();
        $gateway->shouldReceive('retrievePaymentIntent')->zeroOrMoreTimes()->andReturn([
            'id' => $payment->provider_order_id,
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
        [$recharge, $payment] = $this->initiatedStripeRecharge();
        $this->makeDue($payment->fresh());

        $gateway = $this->mockStripeClient();
        $gateway->shouldReceive('retrievePaymentIntent')->zeroOrMoreTimes()->andReturn([
            'id' => $payment->provider_order_id,
            'status' => 'processing',
        ]);

        app(WalletRechargeReconciliationService::class)->reconcileDue();

        $recharge->refresh();
        $this->assertSame(WalletRechargeStatus::Requested, $recharge->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('wallet_id', $recharge->wallet_id)->count());
    }

    /**
     * BEHAVIOUR CHANGE, recorded deliberately. The pre-cutover wallet
     * sweep closed the recharge itself on a canceled intent. The shared
     * PaymentAttemptVerifier answers one question — "does the provider
     * confirm payment?" — so a canceled intent is simply "no", and the
     * attempt stays open for the stale-attempt detector to surface to an
     * operator (the same treatment package reconciliation gives it).
     *
     * The money-safety invariant is unchanged and is what this asserts:
     * an authoritative cancellation never credits a wallet.
     */
    public function test_stripe_reconciliation_never_credits_from_a_canceled_intent(): void
    {
        [$recharge, $payment] = $this->initiatedStripeRecharge();
        $this->makeDue($payment->fresh());

        $gateway = $this->mockStripeClient();
        $gateway->shouldReceive('retrievePaymentIntent')->zeroOrMoreTimes()->andReturn([
            'id' => $payment->provider_order_id,
            'status' => 'canceled',
        ]);

        app(WalletRechargeReconciliationService::class)->reconcileDue();

        $recharge->refresh();
        $this->assertNotSame(WalletRechargeStatus::Succeeded, $recharge->status);
        $this->assertNotSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('wallet_id', $recharge->wallet_id)->count());
        $this->assertSame(0, Wallet::query()->whereKey($recharge->wallet_id)->sole()->balance_minor);
    }

    public function test_stripe_reconciliation_amount_mismatch_never_settles(): void
    {
        [$recharge, $payment] = $this->initiatedStripeRecharge(amountMinor: 50000);
        $this->makeDue($payment->fresh());

        $gateway = $this->mockStripeClient();
        $gateway->shouldReceive('retrievePaymentIntent')->zeroOrMoreTimes()->andReturn([
            'id' => $payment->provider_order_id,
            'status' => 'succeeded',
            'amount_received' => 1,
            'currency' => 'usd',
        ]);

        app(WalletRechargeReconciliationService::class)->reconcileDue();

        $this->assertSame(WalletRechargeStatus::Requested, $recharge->fresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('wallet_id', $recharge->wallet_id)->count());
    }

    public function test_stripe_credit_failed_reconciliation_retry_remains_idempotent(): void
    {
        [$recharge, $payment] = $this->initiatedStripeRecharge();
        Wallet::query()->whereKey($recharge->wallet_id)->update(['status' => WalletStatus::Closed]);

        // Money collected (Payment = Paid), credit outstanding.
        app(PaymentService::class)->transition($payment, PaymentStatus::Paid);
        WalletRecharge::query()->whereKey($recharge->id)->update([
            'status' => WalletRechargeStatus::CreditFailed,
            'failure_code' => 'wallet_not_usable',
        ]);

        app(WalletRechargeReconciliationService::class)->reconcileDue();
        $this->assertSame(WalletRechargeStatus::CreditFailed, $recharge->fresh()->status);

        Wallet::query()->whereKey($recharge->wallet_id)->update(['status' => WalletStatus::Active]);
        app(WalletRechargeReconciliationService::class)->reconcileDue();

        $recharge->refresh();
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->status);
        $this->assertSame(1, WalletLedgerEntry::query()->where('wallet_id', $recharge->wallet_id)->where('entry_type', 'recharge_confirmed')->count());
    }
}
