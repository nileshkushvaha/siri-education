<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Payments\RazorpayPaymentProvider;
use App\Models\Country;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WalletRecharge;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Services\PaymentCallbackVerifier;
use App\Settings\BookingSettings;
use App\Settings\FeatureSettings;
use App\Settings\PaymentGatewaySettings;
use App\Wallet\Contracts\WalletRechargeServiceInterface;
use App\Wallet\Enums\WalletRechargeStatus;
use App\Wallet\Exceptions\WalletException;
use Database\Seeders\CountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Support\EstablishesRechargeMarket;
use Tests\Support\InitiatesWalletRecharges;
use Tests\TestCase;

/**
 * Wallet recharge across every launch market — the recharge counterpart
 * of LaunchMarketPaymentTest, and deliberately its mirror image.
 *
 * The point being pinned is that funding a wallet and paying for a
 * booking are the SAME collection question asked with a different
 * transaction type. Recharge used to answer it for itself, consulting
 * only the resolved provider's currency list, which meant it silently
 * ignored the country market gate, the rollout scope and the
 * platform-wide payments switch. Anything asserted here must therefore
 * hold for exactly the same reasons it holds for a booking.
 *
 * The market list is read from CountrySeeder::LAUNCH_MARKETS rather than
 * restated, so a market added to the canonical source without currency
 * support fails this file instead of slipping through.
 *
 * NZD and SAR are proven BLOCKED rather than given a green path.
 * Razorpay's supported set is per-merchant and their published list is
 * not machine-checkable, so those two stay unattested until an operator
 * confirms them on the account — and a wallet must not be the thing that
 * quietly opens a market payments cannot serve.
 */
final class LaunchMarketWalletRechargeTest extends TestCase
{
    use EstablishesRechargeMarket;
    use InitiatesWalletRecharges;
    use RefreshDatabase;

    private const KEY_SECRET = 'test_key_secret';

    /**
     * Each launch market's billing currency, with a recharge amount in
     * that currency's own minor units. Amounts are arbitrary TEST
     * figures, distinctive enough per market that a cross-wired currency
     * cannot pass silently.
     *
     * @var array<string, array{currency: string, numeric: string, minor: int}>
     */
    private const MARKETS = [
        'IN' => ['currency' => 'INR', 'numeric' => '356', 'minor' => 500000],
        'US' => ['currency' => 'USD', 'numeric' => '840', 'minor' => 10000],
        'GB' => ['currency' => 'GBP', 'numeric' => '826', 'minor' => 8000],
        'AU' => ['currency' => 'AUD', 'numeric' => '036', 'minor' => 15000],
        'CA' => ['currency' => 'CAD', 'numeric' => '124', 'minor' => 12000],
        'AE' => ['currency' => 'AED', 'numeric' => '784', 'minor' => 40000],
        'SG' => ['currency' => 'SGD', 'numeric' => '702', 'minor' => 14000],
        'NZ' => ['currency' => 'NZD', 'numeric' => '554', 'minor' => 16000],
        'SA' => ['currency' => 'SAR', 'numeric' => '682', 'minor' => 38000],
    ];

    /** Markets whose currency Razorpay's public documentation confirms. */
    private const ATTESTED = ['US', 'GB', 'AU', 'CA', 'AE', 'SG'];

    /** Markets whose currency could not be verified — payment-blocked until an operator confirms the account. */
    private const UNATTESTED = ['NZ', 'SA'];

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $features = app(FeatureSettings::class);
        $features->wallet_enabled = true;
        $features->save();
    }

    /** @return iterable<string, array{string}> */
    public static function domesticMarketProvider(): iterable
    {
        yield 'IN' => ['IN'];
    }

    /** @return iterable<string, array{string}> */
    public static function attestedInternationalMarketProvider(): iterable
    {
        foreach (self::ATTESTED as $iso2) {
            yield $iso2 => [$iso2];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unattestedInternationalMarketProvider(): iterable
    {
        foreach (self::UNATTESTED as $iso2) {
            yield $iso2 => [$iso2];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function everyMarketProvider(): iterable
    {
        foreach (array_keys(self::MARKETS) as $iso2) {
            yield $iso2 => [$iso2];
        }
    }

    public function test_the_market_matrix_tracks_the_canonical_launch_market_list(): void
    {
        $this->assertSame(
            array_keys(CountrySeeder::LAUNCH_MARKETS),
            array_keys(self::MARKETS),
            'This matrix must track CountrySeeder::LAUNCH_MARKETS — the one place the launch set is declared.',
        );

        $this->assertSame(
            array_keys(self::MARKETS),
            [...self::domesticMarkets(), ...self::ATTESTED, ...self::UNATTESTED],
            'Every launch market must be classified as domestic, attested, or unattested — none may be silently untested.',
        );
    }

    /** @return list<string> */
    private static function domesticMarkets(): array
    {
        return ['IN'];
    }

    // ── Golden path ──────────────────────────────────────────────────────

    #[DataProvider('domesticMarketProvider')]
    public function test_domestic_market_completes_the_full_recharge_path(string $iso2): void
    {
        $this->assertRechargeGoldenPath($iso2, international: false);
    }

    #[DataProvider('attestedInternationalMarketProvider')]
    public function test_attested_international_market_completes_the_full_recharge_path(string $iso2): void
    {
        $this->assertRechargeGoldenPath($iso2, international: true);
    }

    // ── Provider capability gate ─────────────────────────────────────────

    #[DataProvider('unattestedInternationalMarketProvider')]
    public function test_unattested_currency_is_blocked_and_creates_no_money_state(string $iso2): void
    {
        $market = $this->seedMarket($iso2);
        $this->activateRazorpay(international: true);

        $this->assertNotContains(
            $market['currency'],
            app(PaymentGatewaySettings::class)->razorpay_international_currencies,
            sprintf('%s must not be assumed collectable — enabling it is an operator action.', $market['currency']),
        );

        $student = $this->studentIn($iso2);

        try {
            app(WalletRechargeServiceInterface::class)->initiate($student, $market['minor']);
            $this->fail(sprintf('%s recharge must be blocked until the merchant account is confirmed.', $market['currency']));
        } catch (WalletException) {
            // The block must be complete: no attempt row, no wallet
            // movement. A refused market must leave no financial trace.
            $this->assertSame(0, WalletRecharge::query()->where('user_id', $student->id)->count());
            $this->assertSame(0, Payment::query()->where('user_id', $student->id)->count());
            $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
        }
    }

    /** An operator who confirms the currency on their account opens the market — no code change. */
    #[DataProvider('unattestedInternationalMarketProvider')]
    public function test_an_operator_confirmed_currency_opens_the_market(string $iso2): void
    {
        $market = $this->seedMarket($iso2);
        $this->activateRazorpay(
            international: true,
            currencies: [...RazorpayPaymentProvider::DEFAULT_INTERNATIONAL_CURRENCIES, $market['currency']],
        );
        $this->expectRazorpayOrder('order_'.$iso2, $market['minor'], $market['currency']);

        [$recharge, $payment] = $this->initiateRecharge($this->studentIn($iso2), $market['minor']);

        $this->assertSame($market['currency'], $recharge->currency_code);
        $this->assertSame($market['minor'], (int) $payment->amount_minor);
    }

    // ── International attestation ────────────────────────────────────────

    #[DataProvider('attestedInternationalMarketProvider')]
    public function test_international_recharge_is_blocked_until_the_merchant_is_attested(string $iso2): void
    {
        $market = $this->seedMarket($iso2);
        $this->activateRazorpay(international: false);

        $this->expectException(WalletException::class);

        try {
            app(WalletRechargeServiceInterface::class)->initiate($this->studentIn($iso2), $market['minor']);
        } finally {
            $this->assertSame(0, WalletRecharge::query()->count());
            $this->assertSame(0, Payment::query()->count());
        }
    }

    /** India is domestic and never depends on the international attestation — revoking it must not break INR recharge. */
    public function test_india_still_recharges_while_international_is_not_attested(): void
    {
        $market = $this->seedMarket('IN');
        $this->activateRazorpay(international: false);
        $this->expectRazorpayOrder('order_IN_DOMESTIC', $market['minor'], 'INR');

        [$recharge] = $this->initiateRecharge($this->studentIn('IN'), $market['minor']);

        $this->assertSame('INR', $recharge->currency_code);
    }

    // ── Market gate ──────────────────────────────────────────────────────

    /**
     * The gate recharge previously did not have at all. A wallet must
     * never be a way into a market payments cannot serve.
     */
    #[DataProvider('everyMarketProvider')]
    public function test_an_inactive_country_cannot_recharge(string $iso2): void
    {
        $market = $this->seedMarket($iso2);
        $this->activateRazorpay(international: true, currencies: [...RazorpayPaymentProvider::DEFAULT_INTERNATIONAL_CURRENCIES, $market['currency']]);

        Country::query()->where('iso2', $iso2)->update(['status' => 'inactive']);

        $student = $this->studentIn($iso2);

        $this->expectException(WalletException::class);

        try {
            app(WalletRechargeServiceInterface::class)->initiate($student, $market['minor']);
        } finally {
            $this->assertSame(0, WalletRecharge::query()->count());
            $this->assertSame(0, Payment::query()->count());
            $this->assertSame(0, WalletLedgerEntry::query()->count());
        }
    }

    /** The platform-wide kill switch reaches wallet recharge, exactly as it reaches booking collection. */
    public function test_disabling_payments_platform_wide_blocks_recharge(): void
    {
        $market = $this->seedMarket('IN');
        $this->activateRazorpay(international: false);

        $gateways = app(PaymentGatewaySettings::class);
        $gateways->payments_enabled = false;
        $gateways->save();

        $this->expectException(WalletException::class);

        try {
            app(WalletRechargeServiceInterface::class)->initiate($this->studentIn('IN'), $market['minor']);
        } finally {
            $this->assertSame(0, WalletRecharge::query()->count());
            $this->assertSame(0, Payment::query()->count());
        }
    }

    // ── Currency integrity ───────────────────────────────────────────────

    /**
     * SIRI records the CUSTOMER's transaction. Razorpay settles the
     * Indian merchant account in INR at its own rate, and that provider
     * fact must never rewrite the student's own currency — the same
     * invariant LaunchMarketPaymentTest asserts for bookings.
     */
    #[DataProvider('attestedInternationalMarketProvider')]
    public function test_a_webhook_reporting_inr_never_credits_an_international_wallet(string $iso2): void
    {
        $market = $this->seedMarket($iso2);
        $this->activateRazorpay(international: true);
        $this->expectRazorpayOrder('order_FX_'.$iso2, $market['minor'], $market['currency']);

        $student = $this->studentIn($iso2);
        [$recharge, $payment] = $this->initiateRecharge($student, $market['minor']);

        // What the merchant account settled in, not what the student
        // paid. It must never rewrite the student's own currency.
        $result = $this->settle($payment, $this->capturedEvent($payment, currencyCode: 'INR'));

        $this->assertTrue($result->ignored);
        $this->assertNotSame(WalletRechargeStatus::Succeeded, $recharge->fresh()->status);
        $this->assertNotSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * initiate → correct provider order → non-authoritative callback →
     * authoritative capture → exactly one ledger credit, in the
     * student's own currency and minor units.
     */
    private function assertRechargeGoldenPath(string $iso2, bool $international): void
    {
        $market = $this->seedMarket($iso2);
        $this->activateRazorpay(international: $international);

        $orderId = 'order_'.$iso2;
        $this->expectRazorpayOrder($orderId, $market['minor'], $market['currency']);

        $student = $this->studentIn($iso2);
        [$recharge, $payment] = $this->initiateRecharge($student, $market['minor']);

        // The Razorpay order carries the STUDENT's own amount and
        // currency, and the generic ledger owns every provider fact.
        $this->assertSame('razorpay', $payment->provider);
        $this->assertSame($market['currency'], $payment->currency_code);
        $this->assertSame($market['minor'], (int) $payment->amount_minor);
        $this->assertSame($orderId, $payment->provider_order_id);
        $this->assertSame(PaymentStatus::Pending, $payment->status);

        // The recharge holds wallet-domain state only.
        $this->assertSame(WalletRechargeStatus::Requested, $recharge->status);
        $this->assertSame($market['currency'], $recharge->currency_code);
        $this->assertSame($market['minor'], (int) $recharge->amount_minor);

        // The browser callback records identity and settles nothing.
        $paymentId = 'pay_'.$iso2;
        app(PaymentCallbackVerifier::class)->verifyRazorpayCheckout(
            $recharge,
            $orderId,
            $paymentId,
            hash_hmac('sha256', "{$orderId}|{$paymentId}", self::KEY_SECRET),
        );

        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(WalletRechargeStatus::Requested, $recharge->refresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
        $this->assertSame(0, Wallet::query()->forUser($student->id)->sole()->balance_minor);

        // The signed webhook is what actually moves money.
        $this->settle($payment->refresh(), $this->capturedEvent($payment->refresh(), providerPaymentId: $paymentId));

        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->refresh()->status);

        // No FX anywhere: the wallet, the ledger entry and the payment
        // all sit in the student's own currency and minor units.
        $wallet = Wallet::query()->forUser($student->id)->sole();
        $this->assertSame($market['currency'], $wallet->currency_code);
        $this->assertSame($market['minor'], $wallet->balance_minor);

        $entries = WalletLedgerEntry::query()->where('user_id', $student->id)->get();
        $this->assertCount(1, $entries, 'Exactly one ledger credit per settled recharge.');
        $this->assertSame($market['currency'], $entries->first()->currency_code);
        $this->assertSame($market['minor'], $entries->first()->amount_minor);

        // A redelivered webhook must not credit a second time.
        $this->settle($payment->refresh(), $this->capturedEvent($payment->refresh(), providerPaymentId: $paymentId));

        $this->assertSame($market['minor'], Wallet::query()->forUser($student->id)->sole()->balance_minor);
        $this->assertSame(1, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
    }

    /** @return array{currency: string, minor: int} */
    private function seedMarket(string $iso2): array
    {
        $market = self::MARKETS[$iso2];

        $this->establishRechargeMarket(
            $iso2,
            $market['currency'],
            provider: CountrySeeder::LAUNCH_MARKETS[$iso2] ?? 'razorpay',
            numericCode: $market['numeric'],
        );

        return ['currency' => $market['currency'], 'minor' => $market['minor']];
    }

    private function studentIn(string $iso2): User
    {
        return $this->attachStudentToMarket(
            User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]),
            Country::query()->where('iso2', $iso2)->sole(),
        );
    }

    /** @param list<string>|null $currencies */
    private function activateRazorpay(bool $international, ?array $currencies = null): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->payments_enabled = true;
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString(self::KEY_SECRET);
        $gateways->razorpay_webhook_secret = Crypt::encryptString('test_webhook_secret');
        $gateways->razorpay_international_enabled = $international;
        $gateways->razorpay_international_currencies = $currencies ?? RazorpayPaymentProvider::DEFAULT_INTERNATIONAL_CURRENCIES;
        $gateways->save();

        $bookings = app(BookingSettings::class);
        $bookings->payment_provider = 'razorpay';
        $bookings->save();
    }

    /** The Orders API must receive the STUDENT's own amount and currency, never an INR conversion. */
    private function expectRazorpayOrder(string $orderId, int $expectedAmount, string $expectedCurrency): void
    {
        $gateway = Mockery::mock(RazorpayGatewayClient::class);
        $gateway->shouldReceive('createOrder')
            ->once()
            ->withArgs(fn (string $keyId, string $keySecret, array $params): bool => $params['amount'] === $expectedAmount
                && $params['currency'] === $expectedCurrency)
            ->andReturn(['id' => $orderId, 'amount' => $expectedAmount, 'currency' => $expectedCurrency]);

        $this->instance(RazorpayGatewayClient::class, $gateway);
    }
}
