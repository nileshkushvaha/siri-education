<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Exceptions\GatewayRequestException;
use App\Livewire\Frontend\Student\WalletOverview;
use App\Models\BookingPayment;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WalletRecharge;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Repositories\WalletFinancialReportRepository;
use App\Reporting\ValueObjects\ReportingPeriod;
use App\Settings\BookingSettings;
use App\Settings\FeatureSettings;
use App\Settings\GeneralSettings;
use App\Settings\PaymentGatewaySettings;
use App\Wallet\Contracts\WalletRechargeServiceInterface;
use App\Wallet\Enums\WalletRechargeStatus;
use App\Wallet\Enums\WalletStatus;
use App\Wallet\Exceptions\WalletException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\Support\EstablishesRechargeMarket;
use Tests\Support\InitiatesWalletRecharges;
use Tests\TestCase;

class StripeWalletRechargeTest extends TestCase
{
    use EstablishesRechargeMarket;
    use InitiatesWalletRecharges;
    use RefreshDatabase;

    private Country $market;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::query()->firstOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar', 'symbol' => '$', 'numeric_code' => '840',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        // Recharge now passes the same market gate as booking
        // collection, so an active US/USD market routed at Stripe is a
        // precondition for every case in this file.
        $this->market = $this->establishRechargeMarket('US', 'USD', provider: 'stripe', numericCode: '840');

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        app(FeatureSettings::class)->wallet_enabled = true;

        // No student in this file sets an explicit billing country, so
        // wallet resolution falls back to the platform default currency
        // — set to USD (a Stripe-supported currency) for this file.
        $general = app(GeneralSettings::class);
        $general->default_currency = 'USD';
        $general->save();

        $this->configureStripe();
    }

    private function configureStripe(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->stripe_enabled = true;
        $gateways->stripe_publishable_key = 'pk_test_wallet_recharge';
        $gateways->stripe_secret_key = Crypt::encryptString('sk_test_wallet_recharge_secret');
        $gateways->stripe_webhook_secret = Crypt::encryptString('test_webhook_secret');
        $gateways->save();

        app(BookingSettings::class)->payment_provider = 'stripe';
        app(BookingSettings::class)->save();
    }

    private function student(): User
    {
        return $this->attachStudentToMarket(
            User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]),
            $this->market,
        );
    }

    private function service(): WalletRechargeServiceInterface
    {
        return app(WalletRechargeServiceInterface::class);
    }

    private function fakeStripeIntentApi(string $intentId = 'pi_TEST123', string $clientSecret = 'pi_TEST123_secret_abc'): Mockery\MockInterface
    {
        $gateway = Mockery::mock(StripeGatewayClient::class);
        $gateway->shouldReceive('createPaymentIntent')->andReturn([
            'id' => $intentId,
            'client_secret' => $clientSecret,
        ]);
        $this->app->instance(StripeGatewayClient::class, $gateway);

        return $gateway;
    }

    // ── Initiation ───────────────────────────────────────────────────────

    public function test_routed_stripe_provider_creates_a_payment_intent_with_exact_stored_amount(): void
    {
        $this->fakeStripeIntentApi();
        $student = $this->student();

        $checkout = $this->service()->initiate($student, 50000);

        $this->assertSame('stripe', $checkout->provider);
        $this->assertSame(50000, $checkout->amountMinor);

        $recharge = WalletRecharge::query()->where('user_id', $student->id)->sole();
        $this->assertSame(50000, $recharge->amount_minor);
        $this->assertSame(WalletRechargeStatus::Requested, $recharge->status);

        // The intent id belongs to the Payment attempt, not the recharge.
        $payment = Payment::query()->whereKey($checkout->paymentId)->sole();
        $this->assertSame('pi_TEST123', $payment->provider_order_id);
        $this->assertSame(WalletRecharge::PAYABLE_TYPE, $payment->payable_type);
    }

    public function test_lowercase_currency_is_sent_to_stripe(): void
    {
        $gateway = Mockery::mock(StripeGatewayClient::class);
        $gateway->shouldReceive('createPaymentIntent')
            ->withArgs(fn (string $secretKey, array $params, string $idempotencyKey): bool => $params['currency'] === 'usd')
            ->once()
            ->andReturn(['id' => 'pi_LOWER1', 'client_secret' => 'secret']);
        $this->app->instance(StripeGatewayClient::class, $gateway);

        $this->service()->initiate($this->student(), 50000);
    }

    public function test_internal_recharge_reference_is_placed_in_safe_metadata(): void
    {
        $captured = null;
        $gateway = Mockery::mock(StripeGatewayClient::class);
        $gateway->shouldReceive('createPaymentIntent')
            ->withArgs(function (string $secretKey, array $params, string $idempotencyKey) use (&$captured): bool {
                // The canonical generic key — the wallet no longer has
                // a metadata dialect of its own.
                $captured = $params['metadata']['payment_reference'] ?? null;

                return true;
            })
            ->once()
            ->andReturn(['id' => 'pi_META1', 'client_secret' => 'secret']);
        $this->app->instance(StripeGatewayClient::class, $gateway);

        $checkout = $this->service()->initiate($this->student(), 50000);

        $this->assertSame($checkout->reference, $captured);
    }

    public function test_stripe_api_idempotency_key_is_the_stable_recharge_reference(): void
    {
        $capturedKey = null;
        $gateway = Mockery::mock(StripeGatewayClient::class);
        $gateway->shouldReceive('createPaymentIntent')
            ->withArgs(function (string $secretKey, array $params, string $idempotencyKey) use (&$capturedKey): bool {
                $capturedKey = $idempotencyKey;

                return true;
            })
            ->once()
            ->andReturn(['id' => 'pi_IDEM1', 'client_secret' => 'secret']);
        $this->app->instance(StripeGatewayClient::class, $gateway);

        $checkout = $this->service()->initiate($this->student(), 50000);

        $this->assertSame($checkout->reference, $capturedKey);
    }

    public function test_client_secret_is_returned_only_to_the_initiating_authenticated_user(): void
    {
        $this->fakeStripeIntentApi('pi_SECRET1', 'pi_SECRET1_secret_xyz');

        $checkout = $this->service()->initiate($this->student(), 50000);

        $this->assertSame('pi_SECRET1_secret_xyz', $checkout->checkoutPayload['client_secret']);
        $this->assertSame('pk_test_wallet_recharge', $checkout->checkoutPayload['publishable_key']);
    }

    public function test_client_secret_is_not_persisted_anywhere(): void
    {
        $this->fakeStripeIntentApi('pi_NOPERSIST1', 'pi_NOPERSIST1_secret_xyz');
        $student = $this->student();

        $checkout = $this->service()->initiate($student, 50000);

        $recharge = WalletRecharge::query()->where('user_id', $student->id)->sole();
        $payment = Payment::query()->whereKey($checkout->paymentId)->sole();

        $this->assertSame('pi_NOPERSIST1', $payment->provider_order_id);

        // The single-use client_secret must not reach EITHER record.
        foreach ([$recharge->toArray(), $payment->toArray()] as $row) {
            $this->assertStringNotContainsString('pi_NOPERSIST1_secret_xyz', (string) json_encode($row));
        }
        $this->assertStringNotContainsString('secret', (string) ($recharge->metadata ?? ''));
    }

    public function test_provider_creation_failure_creates_no_ledger_entry(): void
    {
        $gateway = Mockery::mock(StripeGatewayClient::class);
        $gateway->shouldReceive('createPaymentIntent')->andThrow(new GatewayRequestException('network error'));
        $this->app->instance(StripeGatewayClient::class, $gateway);

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

    public function test_capability_false_prevents_unusable_recharge_initiation(): void
    {
        // Simulate the pre-Phase-25E state directly against the resolved
        // capability check rather than mutating the shared provider class.
        app(BookingSettings::class)->payment_provider = 'razorpay';
        app(BookingSettings::class)->save();
        app(PaymentGatewaySettings::class)->allowed_providers = ['razorpay'];
        app(PaymentGatewaySettings::class)->save();

        $this->fakeStripeIntentApi();
        $student = $this->student();

        // Razorpay only supports INR; this USD student has no usable
        // provider once Stripe is excluded from allowed_providers.
        $this->expectException(WalletException::class);

        $this->service()->initiate($student, 50000);
    }

    /**
     * The wallet currency now follows the student's own MARKET, so an
     * unsupported currency is expressed as a market whose billing
     * currency the provider cannot collect — JPY, which is genuinely
     * absent from StripePaymentProvider::SUPPORTED_CURRENCIES. This used
     * to work by leaving the student countryless and switching the
     * platform default currency, which no longer describes anything
     * reachable: a student with no active market cannot recharge at all,
     * for a different and more fundamental reason.
     */
    public function test_unsupported_currency_rejects_safely(): void
    {
        $japan = $this->establishRechargeMarket('JP', 'JPY', provider: 'stripe', numericCode: '392');
        Currency::query()->where('code', 'JPY')->update(['minor_units' => 0]);

        $this->fakeStripeIntentApi();
        $student = $this->attachStudentToMarket(
            User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]),
            $japan,
        );

        $this->expectException(WalletException::class);

        $this->service()->initiate($student, 5000);
    }

    public function test_repeated_initiation_is_rate_limited(): void
    {
        $student = $this->student();
        RateLimiter::clear('wallet-recharge-initiate:'.$student->id);

        $gateway = Mockery::mock(StripeGatewayClient::class);
        $gateway->shouldReceive('createPaymentIntent')->andReturnUsing(fn (): array => [
            'id' => 'pi_RATE_'.uniqid(), 'client_secret' => 'secret',
        ]);
        $this->app->instance(StripeGatewayClient::class, $gateway);

        // Applied manually in WalletOverview::initiateRecharge() via
        // ThrottlesLivewireRequests — Livewire actions never hit
        // route-level throttle middleware, so this must be driven
        // through the real Livewire component, not the service alone.
        $component = Livewire::actingAs($student)->test(WalletOverview::class);

        for ($i = 0; $i < 5; $i++) {
            $component->set('rechargeAmount', '500')->call('initiateRecharge');
        }

        $this->assertSame(5, WalletRecharge::query()->where('user_id', $student->id)->count());

        $component->set('rechargeAmount', '500')->call('initiateRecharge');

        $this->assertSame(
            5,
            WalletRecharge::query()->where('user_id', $student->id)->count(),
            'A 6th attempt within the same minute must be rate-limited, not create a 6th provider order.',
        );
        $this->assertStringContainsString('Too many attempts', $component->get('rechargeBanner'));
    }

    // ── Settlement smoke test (full webhook coverage lives in WalletRechargeWebhookTest) ──

    public function test_stripe_settlement_credits_exact_minor_units_with_correct_source_linkage(): void
    {
        $this->fakeStripeIntentApi('pi_SETTLE1');
        $student = $this->student();
        [$recharge, $payment] = $this->initiateRecharge($student, 50000);

        $result = $this->settle($payment, $this->capturedEvent($payment));

        $this->assertTrue($result->settled);
        $wallet = Wallet::query()->forUser($student->id)->sole();
        $this->assertSame(50000, $wallet->balance_minor);

        $entry = WalletLedgerEntry::query()->where('user_id', $student->id)->where('entry_type', 'recharge_confirmed')->sole();
        $this->assertSame(WalletRecharge::class, $entry->source_type);
        $this->assertSame($recharge->id, $entry->source_id);
    }

    // ── Reporting ────────────────────────────────────────────────────────

    public function test_stripe_recharge_confirmed_appears_in_wallet_movements_and_is_not_booking_revenue(): void
    {
        $this->fakeStripeIntentApi('pi_REPORT1');
        $student = $this->student();
        [, $payment] = $this->initiateRecharge($student, 50000);

        $this->settle($payment, $this->capturedEvent($payment));

        $period = ReportingPeriod::custom(CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay());
        $movements = app(WalletFinancialReportRepository::class)->movements($period, new ReportFilters(period: $period));

        $rechargeMovement = collect($movements)->first(fn (array $m): bool => $m['entryType'] === 'recharge_confirmed' && $m['currency'] === 'USD');
        $this->assertNotNull($rechargeMovement);
        $this->assertSame(50000, $rechargeMovement['amountMinor']);

        // Never classified as booking revenue — the wallet report is a
        // completely separate repository from PaymentFinancialReportRepository.
        $this->assertSame(0, BookingPayment::query()->count());
    }

    public function test_stripe_captured_but_uncredited_attempt_appears_in_operational_reporting(): void
    {
        $this->fakeStripeIntentApi('pi_UNCREDITED1');
        $student = $this->student();
        [$recharge, $payment] = $this->initiateRecharge($student, 50000);

        // Close the wallet initiate() already created, so settlement's
        // own credit attempt fails and the recharge becomes CreditFailed.
        Wallet::query()->whereKey($recharge->wallet_id)->update(['status' => WalletStatus::Closed]);

        $this->settle($payment, $this->capturedEvent($payment));

        $uncredited = app(WalletFinancialReportRepository::class)->uncreditedCapturedRecharges();
        $this->assertNotEmpty(array_filter($uncredited, fn (array $row): bool => $row['id'] === $recharge->id));
    }
}
