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
use App\Notifications\Wallet\WalletRechargeSucceededNotification;
use App\Payments\Enums\PaymentStatus;
use App\Services\Payment\PaymentWebhookSignatureService;
use App\Settings\BookingSettings;
use App\Settings\FeatureSettings;
use App\Settings\PaymentGatewaySettings;
use App\Wallet\Enums\WalletRechargeStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\Support\EstablishesRechargeMarket;
use Tests\Support\InitiatesWalletRecharges;
use Tests\TestCase;

class WalletRechargeWebhookTest extends TestCase
{
    use EstablishesRechargeMarket;
    use InitiatesWalletRecharges;
    use RefreshDatabase;

    private Country $india;

    private const KEY_SECRET = 'test_key_secret';

    private const WEBHOOK_SECRET = 'test_webhook_secret';

    private const STRIPE_WEBHOOK_SECRET = 'test_stripe_webhook_secret';

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
        $gateways->razorpay_webhook_secret = Crypt::encryptString(PaymentWebhookSignatureService::PURPOSE_WALLET.':'.self::WEBHOOK_SECRET);
        $gateways->stripe_enabled = true;
        $gateways->stripe_publishable_key = 'pk_test_wallet_recharge';
        $gateways->stripe_secret_key = Crypt::encryptString('sk_test_wallet_recharge_secret');
        $gateways->stripe_webhook_secret = Crypt::encryptString(PaymentWebhookSignatureService::PURPOSE_WALLET.':'.self::STRIPE_WEBHOOK_SECRET);
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

    private function fakeRazorpayOrderApi(string $orderId): void
    {
        $gateway = Mockery::mock(RazorpayGatewayClient::class);
        $gateway->shouldReceive('createOrder')->andReturn(['id' => $orderId]);
        $this->app->instance(RazorpayGatewayClient::class, $gateway);
    }

    /**
     * A recharge with a live Razorpay attempt. Returns the PAYMENT,
     * because that is what a provider webhook names: `payment_reference`
     * in the order notes is the attempt's idempotency key, and the
     * recharge is reached from the attempt's payable link.
     *
     * @return array{0: WalletRecharge, 1: Payment}
     */
    private function initiatedRecharge(string $orderId, int $amountMinor = 50000): array
    {
        $this->fakeRazorpayOrderApi($orderId);

        return $this->initiateRecharge($this->student(), $amountMinor);
    }

    // ── Stripe fixtures ──────────────────────────────────────────────────

    private function fakeStripeIntentApi(string $intentId): void
    {
        $gateway = Mockery::mock(StripeGatewayClient::class);
        $gateway->shouldReceive('createPaymentIntent')->andReturn(['id' => $intentId, 'client_secret' => $intentId.'_secret']);
        $this->app->instance(StripeGatewayClient::class, $gateway);
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
    private function initiatedStripeRecharge(string $intentId, int $amountMinor = 50000): array
    {
        $this->fakeStripeIntentApi($intentId);

        $usa = $this->establishRechargeMarket('US', 'USD', provider: 'stripe', numericCode: '840');

        $student = $this->attachStudentToMarket(
            User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]),
            $usa,
        );

        return $this->initiateRecharge($student, $amountMinor);
    }

    private function postStripeWebhook(array $payload): TestResponse
    {
        $body = (string) json_encode($payload);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", self::STRIPE_WEBHOOK_SECRET);

        return $this->call('POST', '/api/webhooks/wallets/recharges/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    private function stripeSucceededPayload(string $intentId, string $reference, int $amountReceived = 50000, string $currency = 'usd'): array
    {
        return [
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => $intentId,
                    'amount' => $amountReceived,
                    'amount_received' => $amountReceived,
                    'currency' => $currency,
                    'status' => 'succeeded',
                    'metadata' => ['payment_reference' => $reference],
                ],
            ],
        ];
    }

    private function postWebhook(array $payload): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', '/api/webhooks/wallets/recharges/razorpay', [], [], [], [
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    private function capturedPayload(string $orderId, string $paymentId, string $reference, int $amountMinor = 50000): array
    {
        return [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => $paymentId,
                        'order_id' => $orderId,
                        'amount' => $amountMinor,
                        'currency' => 'INR',
                        // The canonical generic key the shared parser reads
                        // — the wallet no longer has a payload dialect of
                        // its own.
                        'notes' => ['payment_reference' => $reference],
                    ],
                ],
            ],
        ];
    }

    public function test_a_verified_captured_webhook_credits_the_wallet(): void
    {
        [$recharge, $payment] = $this->initiatedRecharge('order_WH1');

        $this->postWebhook($this->capturedPayload('order_WH1', 'pay_WH1', $payment->idempotency_key))
            ->assertOk()
            ->assertJson(['status' => 'processed']);

        $recharge->refresh();
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->status);

        $wallet = Wallet::query()->whereKey($recharge->wallet_id)->sole();
        $this->assertSame(50000, $wallet->balance_minor);
    }

    public function test_an_invalid_signature_is_rejected_and_never_credits(): void
    {
        [$recharge, $payment] = $this->initiatedRecharge('order_WH2');
        $body = (string) json_encode($this->capturedPayload('order_WH2', 'pay_WH2', $payment->idempotency_key));

        $this->call('POST', '/api/webhooks/wallets/recharges/razorpay', [], [], [], [
            'HTTP_X_RAZORPAY_SIGNATURE' => 'not-a-real-signature',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body)->assertStatus(401);

        $this->assertSame(WalletRechargeStatus::Requested, $recharge->fresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $recharge->user_id)->count());
    }

    public function test_a_duplicate_webhook_delivery_is_ignored_and_does_not_double_credit(): void
    {
        [$recharge, $payment] = $this->initiatedRecharge('order_WH3');
        $payload = $this->capturedPayload('order_WH3', 'pay_WH3', $payment->idempotency_key);

        $this->postWebhook($payload)->assertOk()->assertJson(['status' => 'processed']);
        // A redelivery is a REPLAY, not an "ignored" event: the money is
        // collected and the wallet is credited, there is simply no new
        // work. Collapsing the two would lose that distinction in the
        // audit trail.
        $this->postWebhook($payload)->assertOk()->assertJson(['status' => 'replayed']);

        $wallet = Wallet::query()->whereKey($recharge->wallet_id)->sole();
        $this->assertSame(50000, $wallet->balance_minor);
        $this->assertSame(1, WalletLedgerEntry::query()->where('user_id', $recharge->user_id)->count());
    }

    public function test_an_unknown_reference_is_acknowledged_as_ignored(): void
    {
        $this->postWebhook($this->capturedPayload('order_UNKNOWN', 'pay_UNKNOWN', 'WRCH-DOES-NOT-EXIST'))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);
    }

    public function test_a_provider_amount_mismatch_is_rejected_and_never_credits(): void
    {
        [$recharge, $payment] = $this->initiatedRecharge('order_WH4', amountMinor: 50000);

        $this->postWebhook($this->capturedPayload('order_WH4', 'pay_WH4', $payment->idempotency_key, amountMinor: 1))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        $this->assertSame(WalletRechargeStatus::Requested, $recharge->fresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $recharge->user_id)->count());
    }

    public function test_a_failed_payment_event_marks_the_recharge_failed_without_crediting(): void
    {
        [$recharge, $payment] = $this->initiatedRecharge('order_WH5');

        $payload = [
            'event' => 'payment.failed',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_WH5',
                        'order_id' => 'order_WH5',
                        'amount' => 50000,
                        'currency' => 'INR',
                        'notes' => ['payment_reference' => $payment->idempotency_key],
                        'error_description' => 'card declined',
                    ],
                ],
            ],
        ];

        // Understood, acted on, and deliberately not a settlement — the
        // provider should stop retrying, so 200/ignored rather than 500.
        $this->postWebhook($payload)->assertOk()->assertJson(['status' => 'ignored']);

        // The ATTEMPT failed; the RECHARGE stays open. A failed payment
        // is not a failed intent to add money, and closing the recharge
        // here would force a duplicate domain record for a retry.
        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(WalletRechargeStatus::Requested, $recharge->fresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $recharge->user_id)->count());
    }

    public function test_an_unactionable_event_is_ignored(): void
    {
        [$recharge, $payment] = $this->initiatedRecharge('order_WH6');

        $payload = [
            'event' => 'payment.authorized',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_WH6',
                        'order_id' => 'order_WH6',
                        'amount' => 50000,
                        'currency' => 'INR',
                        'notes' => ['payment_reference' => $payment->idempotency_key],
                    ],
                ],
            ],
        ];

        $this->postWebhook($payload)->assertOk()->assertJson(['status' => 'ignored']);
        $this->assertSame(WalletRechargeStatus::Requested, $recharge->fresh()->status);
    }

    public function test_an_unknown_provider_key_returns_404(): void
    {
        $body = (string) json_encode(['event' => 'payment.captured']);

        // 'paypal' is not a supported recharge webhook provider (only
        // razorpay and stripe are) — a genuinely unknown key, unlike
        // 'stripe' which is now a real, supported provider.
        $this->call('POST', '/api/webhooks/wallets/recharges/paypal', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(404);
    }

    // ── Stripe ───────────────────────────────────────────────────────────

    public function test_stripe_invalid_signature_never_credits(): void
    {
        [$recharge, $payment] = $this->initiatedStripeRecharge('pi_SWH1');
        $body = (string) json_encode($this->stripeSucceededPayload('pi_SWH1', $payment->idempotency_key));

        $this->call('POST', '/api/webhooks/wallets/recharges/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=not-a-real-signature',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body)->assertStatus(401);

        $this->assertSame(WalletRechargeStatus::Requested, $recharge->fresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $recharge->user_id)->count());
    }

    public function test_stripe_unknown_valid_reference_returns_the_established_safe_response(): void
    {
        $this->postStripeWebhook($this->stripeSucceededPayload('pi_UNKNOWN', 'WRCH-DOES-NOT-EXIST'))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);
    }

    public function test_stripe_payment_intent_succeeded_credits_exact_minor_units(): void
    {
        [$recharge, $payment] = $this->initiatedStripeRecharge('pi_SWH2', amountMinor: 50000);

        $this->postStripeWebhook($this->stripeSucceededPayload('pi_SWH2', $payment->idempotency_key, amountReceived: 50000))
            ->assertOk()
            ->assertJson(['status' => 'processed']);

        $recharge->refresh();
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->status);

        $wallet = Wallet::query()->whereKey($recharge->wallet_id)->sole();
        $this->assertSame(50000, $wallet->balance_minor);
    }

    public function test_stripe_amount_mismatch_never_credits(): void
    {
        [$recharge, $payment] = $this->initiatedStripeRecharge('pi_SWH3', amountMinor: 50000);

        $this->postStripeWebhook($this->stripeSucceededPayload('pi_SWH3', $payment->idempotency_key, amountReceived: 1))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        $this->assertSame(WalletRechargeStatus::Requested, $recharge->fresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $recharge->user_id)->count());
    }

    public function test_stripe_amount_received_mismatch_never_credits(): void
    {
        [$recharge, $payment] = $this->initiatedStripeRecharge('pi_SWH4', amountMinor: 50000);

        // amount (requested) matches, but amount_received (actually
        // captured) does not — the webhook parser must use
        // amount_received, never the merely-requested amount, for a
        // succeeded event.
        $payload = $this->stripeSucceededPayload('pi_SWH4', $payment->idempotency_key, amountReceived: 50000);
        $payload['data']['object']['amount'] = 50000;
        $payload['data']['object']['amount_received'] = 12345;

        $this->postStripeWebhook($payload)->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(WalletRechargeStatus::Requested, $recharge->fresh()->status);
        $this->assertNotSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $recharge->user_id)->count());

        // Two provider facts that disagree is an operator's problem.
        $this->assertDatabaseHas('payment_reconciliation_issues', ['payment_id' => $payment->id]);
    }

    public function test_stripe_currency_mismatch_never_credits(): void
    {
        [$recharge, $payment] = $this->initiatedStripeRecharge('pi_SWH5', amountMinor: 50000);

        $this->postStripeWebhook($this->stripeSucceededPayload('pi_SWH5', $payment->idempotency_key, amountReceived: 50000, currency: 'eur'))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        $this->assertSame(WalletRechargeStatus::Requested, $recharge->fresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $recharge->user_id)->count());
    }

    /**
     * A bogus metadata reference is harmless BECAUSE resolution falls
     * back to the provider's own intent id, which is authoritative
     * identity rather than metadata we asked the provider to echo. The
     * safety property is not "it refuses" but "it can only ever settle
     * the attempt that intent genuinely belongs to".
     */
    public function test_stripe_settles_the_correct_attempt_despite_a_bogus_metadata_reference(): void
    {
        [$recharge, $payment] = $this->initiatedStripeRecharge('pi_SWH6', amountMinor: 50000);

        $this->postStripeWebhook($this->stripeSucceededPayload('pi_SWH6', 'WRCH-WRONG-REFERENCE', amountReceived: 50000))
            ->assertOk()
            ->assertJson(['status' => 'processed']);

        // Settled — and settled against the right recharge.
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->fresh()->status);
        $this->assertSame(1, WalletLedgerEntry::query()->where('user_id', $recharge->user_id)->count());
        $this->assertSame(50000, Wallet::query()->forUser($recharge->user_id)->sole()->balance_minor);
    }

    public function test_stripe_payment_intent_id_conflict_never_credits(): void
    {
        [$recharge, $payment] = $this->initiatedStripeRecharge('pi_SWH7', amountMinor: 50000);

        // The metadata reference is correct, but the intent id in the
        // payload disagrees with the one already stored from initiation
        // — must never silently settle using our own stored id instead.
        $payload = $this->stripeSucceededPayload('pi_SWH7_DIFFERENT', $payment->idempotency_key, amountReceived: 50000);

        $this->postStripeWebhook($payload)->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(WalletRechargeStatus::Requested, $recharge->fresh()->status);
        $this->assertNotSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $recharge->user_id)->count());

        // Two provider facts that disagree is an operator's problem.
        $this->assertDatabaseHas('payment_reconciliation_issues', ['payment_id' => $payment->id]);
    }

    public function test_stripe_processing_event_never_credits(): void
    {
        [$recharge, $payment] = $this->initiatedStripeRecharge('pi_SWH8');

        $payload = [
            'type' => 'payment_intent.processing',
            'data' => [
                'object' => [
                    'id' => 'pi_SWH8',
                    'amount' => 50000,
                    'currency' => 'usd',
                    'status' => 'processing',
                    'metadata' => ['payment_reference' => $payment->idempotency_key],
                ],
            ],
        ];

        $this->postStripeWebhook($payload)->assertOk()->assertJson(['status' => 'ignored']);
        $this->assertSame(WalletRechargeStatus::Requested, $recharge->fresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $recharge->user_id)->count());
    }

    public function test_stripe_authoritative_terminal_failure_never_credits(): void
    {
        [$recharge, $payment] = $this->initiatedStripeRecharge('pi_SWH9');

        $payload = [
            'type' => 'payment_intent.payment_failed',
            'data' => [
                'object' => [
                    'id' => 'pi_SWH9',
                    'amount' => 50000,
                    'currency' => 'usd',
                    'status' => 'requires_payment_method',
                    'metadata' => ['payment_reference' => $payment->idempotency_key],
                    'last_payment_error' => ['message' => 'Your card was declined.'],
                ],
            ],
        ];

        // Understood and acted on, but not a settlement.
        $this->postStripeWebhook($payload)->assertOk()->assertJson(['status' => 'ignored']);

        // The ATTEMPT failed; the RECHARGE stays open for a retry.
        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(WalletRechargeStatus::Requested, $recharge->fresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $recharge->user_id)->count());
    }

    public function test_stripe_duplicate_succeeded_webhook_creates_exactly_one_ledger_entry(): void
    {
        [$recharge, $payment] = $this->initiatedStripeRecharge('pi_SWH10', amountMinor: 50000);
        $payload = $this->stripeSucceededPayload('pi_SWH10', $payment->idempotency_key, amountReceived: 50000);

        $this->postStripeWebhook($payload)->assertOk()->assertJson(['status' => 'processed']);
        $this->postStripeWebhook($payload)->assertOk()->assertJson(['status' => 'replayed']);

        $wallet = Wallet::query()->whereKey($recharge->wallet_id)->sole();
        $this->assertSame(50000, $wallet->balance_minor);
        $this->assertSame(1, WalletLedgerEntry::query()->where('user_id', $recharge->user_id)->count());
    }

    public function test_stripe_late_webhook_success_after_a_pending_browser_result_credits_exactly_once(): void
    {
        [$recharge, $payment] = $this->initiatedStripeRecharge('pi_SWH11', amountMinor: 50000);

        // The browser only ever polls (pollWalletRechargeStatus()); the
        // recharge legitimately stays ProviderCreated/AwaitingConfirmation
        // for a while before the webhook (the only settlement path for
        // Stripe) eventually arrives.
        $this->assertSame(WalletRechargeStatus::Requested, $recharge->status);

        $this->postStripeWebhook($this->stripeSucceededPayload('pi_SWH11', $payment->idempotency_key, amountReceived: 50000))
            ->assertOk()
            ->assertJson(['status' => 'processed']);

        $recharge->refresh();
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->status);
        $this->assertSame(1, WalletLedgerEntry::query()->where('user_id', $recharge->user_id)->count());
    }

    public function test_stripe_successful_settlement_produces_correct_source_linkage_and_dispatches_notification_once(): void
    {
        Notification::fake();
        [$recharge, $payment] = $this->initiatedStripeRecharge('pi_SWH12', amountMinor: 50000);

        $this->postStripeWebhook($this->stripeSucceededPayload('pi_SWH12', $payment->idempotency_key, amountReceived: 50000))
            ->assertOk();

        $entry = WalletLedgerEntry::query()->where('user_id', $recharge->user_id)->where('entry_type', 'recharge_confirmed')->sole();
        $this->assertSame(WalletRecharge::class, $entry->source_type);
        $this->assertSame($recharge->id, $entry->source_id);

        $student = User::query()->findOrFail($recharge->user_id);
        Notification::assertSentToTimes($student, WalletRechargeSucceededNotification::class, 1);
    }
}
