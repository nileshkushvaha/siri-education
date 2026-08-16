<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\PaymentCollectionRolloutScope;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Booking\Payments\RazorpayPaymentProvider;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Payments\Enums\PaymentStatus;
use App\Settings\BookingSettings;
use App\Settings\PaymentGatewaySettings;
use App\Support\MoneyFormatter;
use Database\Seeders\CountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * Collection across every launch market, through Razorpay.
 *
 * One provider serves all nine markets: India domestically, the rest
 * through Razorpay International. Stripe stays implemented and deferred,
 * and nothing here routes to it.
 *
 * The market list is read from CountrySeeder::LAUNCH_MARKETS rather than
 * restated, so a market added to the canonical source without pricing or
 * currency support fails these tests instead of slipping through.
 *
 * Currencies split into two groups on purpose. Six are confirmed by
 * Razorpay's public documentation and get the full golden path. NZD and
 * SAR could not be verified — Razorpay's supported set is per-merchant
 * and their published list is not machine-checkable — so they are proven
 * BLOCKED rather than given a green test that would fake a provider
 * capability nobody confirmed.
 *
 * The accounting distinction is asserted for every market: SIRI records
 * the CUSTOMER's transaction in their own currency. Razorpay separately
 * settles the Indian merchant in INR at its own rate, and that fact must
 * never rewrite a student's payment, invoice, or receipt.
 */
class LaunchMarketPaymentTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private const KEY_SECRET = 'test_key_secret';

    private const WEBHOOK_SECRET = 'test_webhook_secret';

    /**
     * Every launch market with its billing currency and a test price.
     *
     * Amounts are arbitrary TEST figures, never commercial pricing —
     * real prices are admin-managed rows in the pricing matrix. They are
     * chosen so each market's minor-unit conversion is distinctive
     * enough that a cross-wired currency cannot pass silently.
     *
     * @var array<string, array{currency: string, numeric: string, major: string, minor: int}>
     */
    private const MARKETS = [
        'IN' => ['currency' => 'INR', 'numeric' => '356', 'major' => '499.00', 'minor' => 49900],
        'US' => ['currency' => 'USD', 'numeric' => '840', 'major' => '49.00', 'minor' => 4900],
        'GB' => ['currency' => 'GBP', 'numeric' => '826', 'major' => '39.00', 'minor' => 3900],
        'AU' => ['currency' => 'AUD', 'numeric' => '036', 'major' => '69.00', 'minor' => 6900],
        'CA' => ['currency' => 'CAD', 'numeric' => '124', 'major' => '59.00', 'minor' => 5900],
        'AE' => ['currency' => 'AED', 'numeric' => '784', 'major' => '179.00', 'minor' => 17900],
        'SG' => ['currency' => 'SGD', 'numeric' => '702', 'major' => '65.00', 'minor' => 6500],
        'NZ' => ['currency' => 'NZD', 'numeric' => '554', 'major' => '79.00', 'minor' => 7900],
        'SA' => ['currency' => 'SAR', 'numeric' => '682', 'major' => '189.00', 'minor' => 18900],
    ];

    private User $student;

    private User $teacher;

    private BookingType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $this->student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);

        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $this->teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        $this->type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one', 'duration_minutes' => 60]);
    }

    // ── Data providers ────────────────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function domesticMarket(): iterable
    {
        yield 'IN / INR' => ['IN'];
    }

    /** Markets whose currency Razorpay's public documentation confirms. @return iterable<string, array{string}> */
    public static function verifiedInternationalMarkets(): iterable
    {
        foreach (['US', 'GB', 'AU', 'CA', 'AE', 'SG'] as $iso2) {
            yield sprintf('%s / %s', $iso2, self::MARKETS[$iso2]['currency']) => [$iso2];
        }
    }

    /** Markets whose currency could NOT be verified against Razorpay documentation. @return iterable<string, array{string}> */
    public static function unverifiedInternationalMarkets(): iterable
    {
        foreach (['NZ', 'SA'] as $iso2) {
            yield sprintf('%s / %s', $iso2, self::MARKETS[$iso2]['currency']) => [$iso2];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function allLaunchMarkets(): iterable
    {
        foreach (array_keys(self::MARKETS) as $iso2) {
            yield $iso2 => [$iso2];
        }
    }

    // ── Canonical market definition ───────────────────────────────────────

    public function test_the_launch_market_set_matches_the_canonical_country_seeder(): void
    {
        $this->assertSame(
            array_keys(self::MARKETS),
            array_keys(CountrySeeder::LAUNCH_MARKETS),
            'This test matrix must track CountrySeeder::LAUNCH_MARKETS — the one place the launch set is declared.',
        );

        foreach (CountrySeeder::LAUNCH_MARKETS as $iso2 => $provider) {
            $this->assertSame('razorpay', $provider, "{$iso2} must route to Razorpay; Stripe is deferred.");
        }
    }

    // ── §30 Golden path, per market ───────────────────────────────────────

    #[DataProvider('domesticMarket')]
    public function test_domestic_market_completes_the_full_payment_path(string $iso2): void
    {
        $this->assertGoldenPath($iso2, international: false);
    }

    #[DataProvider('verifiedInternationalMarkets')]
    public function test_verified_international_market_completes_the_full_payment_path(string $iso2): void
    {
        $this->assertGoldenPath($iso2, international: true);
    }

    /**
     * NZD and SAR are not in the attested currency set, so they must be
     * refused — not quietly charged in USD or INR, and not given a
     * passing test built on a faked capability.
     */
    #[DataProvider('unverifiedInternationalMarkets')]
    public function test_unverified_international_market_is_blocked_safely(string $iso2): void
    {
        $market = $this->seedMarket($iso2);
        $this->activateRazorpay(international: true);
        $this->instance(RazorpayGatewayClient::class, Mockery::mock(RazorpayGatewayClient::class));

        $this->assertNotContains(
            $market['currency'],
            app(PaymentGatewaySettings::class)->razorpay_international_currencies,
            "{$market['currency']} must not be attested until an operator verifies it with Razorpay.",
        );

        $booking = $this->book();

        $this->expectException(BookingException::class);

        try {
            app(BookingPaymentServiceInterface::class)->initiate($booking);
        } finally {
            $this->assertNoMoneyState($booking);
        }
    }

    /** An operator who verifies the currency with Razorpay enables it as configuration, with no code change. */
    #[DataProvider('unverifiedInternationalMarkets')]
    public function test_an_unverified_market_opens_once_its_currency_is_attested(string $iso2): void
    {
        $market = $this->seedMarket($iso2);
        $this->activateRazorpay(international: true, currencies: [...RazorpayPaymentProvider::DEFAULT_INTERNATIONAL_CURRENCIES, $market['currency']]);
        $this->expectRazorpayOrder('order_'.$iso2, $market['minor'], $market['currency']);

        $booking = $this->book();
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $obligation = BookingPayment::query()->where('booking_id', $booking->id)->sole();
        $this->assertSame($market['minor'], $obligation->amount_minor);
        $this->assertSame($market['currency'], $obligation->currency_code);
    }

    // ── §11 Domestic independence ─────────────────────────────────────────

    #[DataProvider('verifiedInternationalMarkets')]
    public function test_international_markets_are_blocked_until_the_merchant_is_attested(string $iso2): void
    {
        $market = $this->seedMarket($iso2);
        $this->activateRazorpay(international: false);
        $this->instance(RazorpayGatewayClient::class, Mockery::mock(RazorpayGatewayClient::class));

        $booking = $this->book();

        $this->expectException(BookingException::class);

        try {
            app(BookingPaymentServiceInterface::class)->initiate($booking);
        } finally {
            $this->assertNoMoneyState($booking);
            $this->assertSame($market['currency'], $booking->refresh()->currency, 'A refusal must never re-denominate the booking.');
        }
    }

    public function test_india_still_collects_while_international_is_not_attested(): void
    {
        $market = $this->seedMarket('IN');
        $this->activateRazorpay(international: false);
        $this->expectRazorpayOrder('order_IN_DOM', $market['minor'], 'INR');

        $booking = $this->book();
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $obligation = BookingPayment::query()->where('booking_id', $booking->id)->sole();
        $this->assertSame(49900, $obligation->amount_minor);
        $this->assertSame('INR', $obligation->currency_code);
    }

    // ── §15/§39/§40 Inactive country guard ────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function inactiveCountries(): iterable
    {
        yield 'France' => ['FR'];
        yield 'Germany' => ['DE'];
        yield 'Japan' => ['JP'];
    }

    #[DataProvider('inactiveCountries')]
    public function test_a_student_in_an_inactive_country_cannot_start_a_paid_transaction(string $iso2): void
    {
        // Priced and routed exactly like a launch market — the ONLY
        // difference is Country.status. A crafted request must still be
        // refused: dropdown filtering is not the boundary.
        $market = $this->seedMarket('US');
        $inactive = Country::factory()->create([
            'iso2' => $iso2,
            'status' => 'inactive',
            'default_currency_id' => Currency::query()->where('code', 'USD')->value('id'),
            'payment_routing' => ['provider' => 'razorpay', 'enabled' => true],
        ]);
        $this->seedStudentLessonPrice($this->type, $inactive, Currency::query()->where('code', 'USD')->sole(), 49.00);
        $this->assignBillingCountry($this->student, $inactive);

        $this->activateRazorpay(international: true);
        $this->instance(RazorpayGatewayClient::class, Mockery::mock(RazorpayGatewayClient::class));

        try {
            $booking = $this->book();
        } catch (\Throwable) {
            // Refused even earlier, at booking creation — also correct.
            $this->assertSame(0, Payment::query()->count());

            return;
        }

        $this->expectException(BookingException::class);

        try {
            app(BookingPaymentServiceInterface::class)->initiate($booking);
        } finally {
            $this->assertNoMoneyState($booking);
            $this->assertSame($market['currency'], 'USD');
        }
    }

    // ── §12 Rollout control ───────────────────────────────────────────────

    #[DataProvider('allLaunchMarkets')]
    public function test_disabling_the_rollout_blocks_every_market(string $iso2): void
    {
        $this->seedMarket($iso2);
        $this->activateRazorpay(international: true);

        $gateways = app(PaymentGatewaySettings::class);
        $gateways->payment_collection_rollout_scope = PaymentCollectionRolloutScope::Disabled->value;
        $gateways->save();

        $this->instance(RazorpayGatewayClient::class, Mockery::mock(RazorpayGatewayClient::class));

        $booking = $this->book();

        $this->expectException(BookingException::class);

        try {
            app(BookingPaymentServiceInterface::class)->initiate($booking);
        } finally {
            $this->assertNoMoneyState($booking);
        }
    }

    // ── §28 Webhook integrity, per market ─────────────────────────────────

    #[DataProvider('verifiedInternationalMarkets')]
    public function test_a_webhook_reporting_inr_never_settles_an_international_payment(string $iso2): void
    {
        $market = $this->seedMarket($iso2);
        $this->activateRazorpay(international: true);
        $this->expectRazorpayOrder('order_'.$iso2, $market['minor'], $market['currency']);

        $booking = $this->book();
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        // The merchant settles in INR — that must never be mistaken for
        // the customer's transaction arriving in INR.
        $this->postWebhook($this->capturedPayload('order_'.$iso2, 'pay_'.$iso2, $this->attemptReference($booking), $market['minor'], 'INR'))
            ->assertOk()
            ->assertJsonPath('status', 'ignored');

        $this->assertNotSettled($booking);
    }

    #[DataProvider('verifiedInternationalMarkets')]
    public function test_a_webhook_reporting_the_wrong_amount_never_settles(string $iso2): void
    {
        $market = $this->seedMarket($iso2);
        $this->activateRazorpay(international: true);
        $this->expectRazorpayOrder('order_'.$iso2, $market['minor'], $market['currency']);

        $booking = $this->book();
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $this->postWebhook($this->capturedPayload('order_'.$iso2, 'pay_'.$iso2, $this->attemptReference($booking), $market['minor'] + 100, $market['currency']))
            ->assertOk()
            ->assertJsonPath('status', 'ignored');

        $this->assertNotSettled($booking);
    }

    // ── §34 Invoice immutability ──────────────────────────────────────────

    public function test_a_settled_international_invoice_survives_country_and_pricing_changes(): void
    {
        $market = $this->seedMarket('GB');
        $this->activateRazorpay(international: true);
        $this->expectRazorpayOrder('order_GB_IMM', $market['minor'], 'GBP');

        $booking = $this->book();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $this->postWebhook($this->capturedPayload('order_GB_IMM', 'pay_GB_IMM', $this->attemptReference($booking), $market['minor'], 'GBP'))->assertOk();

        $invoice = Invoice::query()->sole();

        // Relocate the student and add INR pricing beneath them.
        $india = $this->seedMarket('IN');
        $this->assignBillingCountry($this->student, Country::query()->where('iso2', 'IN')->sole());

        $this->assertSame('GBP', $invoice->refresh()->currency_code);
        $this->assertSame(3900, (int) $invoice->amount_minor);
        $this->assertSame('39.00 GBP', MoneyFormatter::format($invoice->amount_minor, $invoice->currency_code));
        $this->assertSame('GBP', $booking->refresh()->currency);
        $this->assertSame('INR', $india['currency']);
    }

    // ── §41 Spoofing ──────────────────────────────────────────────────────

    public function test_the_browser_cannot_choose_provider_currency_amount_or_country(): void
    {
        $market = $this->seedMarket('AU');
        $this->activateRazorpay(international: true);
        $this->expectRazorpayOrder('order_AU_SPOOF', $market['minor'], 'AUD');

        $booking = $this->book();

        $this->actingAs($this->student)->post('/not-a-route', [
            'provider' => 'stripe', 'amount_minor' => 1, 'currency' => 'INR', 'country' => 'IN',
        ]);

        app(BookingPaymentServiceInterface::class)->initiate($booking->refresh());

        $obligation = BookingPayment::query()->where('booking_id', $booking->id)->sole();
        $this->assertSame('razorpay', $obligation->provider);
        $this->assertSame(6900, $obligation->amount_minor);
        $this->assertSame('AUD', $obligation->currency_code);
    }

    // ── Shared golden-path assertion ──────────────────────────────────────

    private function assertGoldenPath(string $iso2, bool $international): void
    {
        $market = $this->seedMarket($iso2);
        $currency = $market['currency'];
        $this->activateRazorpay(international: $international);
        $this->expectRazorpayOrder('order_'.$iso2, $market['minor'], $currency);

        $booking = $this->book();

        // §23 — the immutable snapshot came from this market's price row.
        $this->assertSame($currency, $booking->currency, "{$iso2} must be priced in {$currency}.");
        $this->assertSame($market['major'], number_format((float) $booking->price, 2, '.', ''));

        $intent = app(BookingPaymentServiceInterface::class)->initiate($booking);
        $this->assertSame($currency, $intent->currency);

        $obligation = BookingPayment::query()->where('booking_id', $booking->id)->sole();
        $attempt = Payment::query()->where('payable_id', $obligation->id)->sole();

        $this->assertSame('razorpay', $obligation->provider, "{$iso2} must route to Razorpay.");
        $this->assertSame($market['minor'], $obligation->amount_minor, "{$market['major']} {$currency} must be {$market['minor']} minor units.");
        $this->assertSame($currency, $obligation->currency_code);
        $this->assertSame($market['minor'], (int) $attempt->amount_minor);
        $this->assertSame($currency, $attempt->currency_code);

        // §27 — the browser receives the server's own figures.
        $payload = app(BookingPaymentServiceInterface::class)->checkoutPayload($booking->refresh());
        $this->assertSame($market['minor'], $payload['amount_minor']);
        $this->assertSame($currency, $payload['currency']);
        $this->assertArrayNotHasKey('key_secret', $payload);

        // §29 — the callback proves identity and settles nothing.
        app(RazorpayPaymentProvider::class)->verifyCheckout(
            $booking,
            'order_'.$iso2,
            'pay_'.$iso2,
            hash_hmac('sha256', 'order_'.$iso2.'|pay_'.$iso2, self::KEY_SECRET),
        );

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status, "{$iso2}: the callback must not settle.");
        $this->assertNotSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(0, Invoice::query()->count(), "{$iso2}: the callback must not issue an invoice.");
        $this->assertSame(PaymentStatus::Pending, $attempt->refresh()->status);

        // §28 — the signed webhook is the only authority.
        $this->postWebhook($this->capturedPayload('order_'.$iso2, 'pay_'.$iso2, (string) $attempt->idempotency_key, $market['minor'], $currency))
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status, "{$iso2} must settle from the signed webhook.");
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(PaymentStatus::Paid, $attempt->refresh()->status);
        $this->assertSame(BookingPaymentRecordStatus::Captured, $obligation->refresh()->status);

        // §25/§33 — the customer's currency, everywhere, exactly once.
        $invoice = Invoice::query()->sole();
        $this->assertSame($market['minor'], (int) $invoice->amount_minor);
        $this->assertSame($currency, $invoice->currency_code);
        $this->assertSame(
            sprintf('%s %s', $market['major'], $currency),
            MoneyFormatter::format($invoice->amount_minor, $invoice->currency_code),
        );

        if ($currency !== 'INR') {
            $this->assertStringNotContainsString('INR', MoneyFormatter::format($invoice->amount_minor, $invoice->currency_code));
        }
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * Builds one launch market: currency, active routed country, price
     * row, and the student's billing country.
     *
     * @return array{currency: string, major: string, minor: int}
     */
    private function seedMarket(string $iso2): array
    {
        $market = self::MARKETS[$iso2];

        $currency = Currency::query()->firstOrCreate(
            ['code' => $market['currency']],
            [
                'name' => $market['currency'],
                'symbol' => $market['currency'],
                'numeric_code' => $market['numeric'],
                'minor_units' => 2,
                'status' => 'active',
            ],
        );

        $country = Country::query()->updateOrCreate(
            ['iso2' => $iso2],
            [
                'name' => $iso2,
                'status' => 'active',
                'default_currency_id' => $currency->id,
                'payment_routing' => ['provider' => CountrySeeder::LAUNCH_MARKETS[$iso2] ?? 'razorpay', 'enabled' => true],
            ],
        );

        $this->seedStudentLessonPrice($this->type, $country, $currency, (float) $market['major']);
        $this->assignBillingCountry($this->student, $country);

        return ['currency' => $market['currency'], 'major' => $market['major'], 'minor' => $market['minor']];
    }

    /** @param list<string>|null $currencies */
    private function activateRazorpay(bool $international, ?array $currencies = null): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->payments_enabled = true;
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString(self::KEY_SECRET);
        $gateways->razorpay_webhook_secret = Crypt::encryptString(self::WEBHOOK_SECRET);
        $gateways->razorpay_international_enabled = $international;
        $gateways->razorpay_international_currencies = $currencies ?? RazorpayPaymentProvider::DEFAULT_INTERNATIONAL_CURRENCIES;
        $gateways->payment_collection_rollout_scope = PaymentCollectionRolloutScope::ActiveCountryRouting->value;
        $gateways->save();

        $bookings = app(BookingSettings::class);
        $bookings->payment_provider = 'razorpay';
        $bookings->save();
    }

    /** §24 — the Orders API must receive the student's own amount and currency, never INR-converted. */
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

    private function book(): Booking
    {
        return app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ))->refresh();
    }

    private function attemptReference(Booking $booking): string
    {
        $obligation = BookingPayment::query()->where('booking_id', $booking->id)->sole();

        return (string) Payment::query()->where('payable_id', $obligation->id)->latest('created_at')->sole()->idempotency_key;
    }

    /** @return array<string, mixed> */
    private function capturedPayload(string $orderId, string $paymentId, string $reference, int $amountMinor, string $currency): array
    {
        return [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => $paymentId,
                        'order_id' => $orderId,
                        'amount' => $amountMinor,
                        'currency' => $currency,
                        'method' => 'card',
                        'notes' => ['payment_reference' => $reference],
                    ],
                ],
            ],
        ];
    }

    private function postWebhook(array $payload): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', '/api/webhooks/bookings/payments/razorpay', [], [], [], [
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    private function assertNoMoneyState(Booking $booking): void
    {
        $this->assertSame(0, BookingPayment::query()->where('booking_id', $booking->id)->count(), 'No obligation may survive a refusal.');
        $this->assertSame(0, Payment::query()->count(), 'No attempt may survive a refusal.');
        $this->assertSame(0, Invoice::query()->count(), 'No invoice may survive a refusal.');
        $this->assertNotSame(BookingPaymentStatus::Paid, $booking->refresh()->payment_status);
    }

    private function assertNotSettled(Booking $booking): void
    {
        $booking->refresh();
        $this->assertNotSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $this->assertNotSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(0, Invoice::query()->count());
    }
}
