<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;
use Throwable;

/**
 * International collection routing, end to end, per billing country.
 *
 * SRS §14.6 keeps Version 1 INR-first ("International currency
 * collection may be planned for future after business validation") and
 * §21.11 files multi-currency under future readiness, so nothing here
 * widens a provider's declared reach. These tests pin the boundary that
 * already exists rather than moving it: Razorpay collects INR only,
 * Stripe collects the international set, and a currency neither can
 * collect must fail before any money state is written.
 *
 * The case worth the most here is the one nobody meets until a real
 * foreign student arrives: `default_provider = razorpay` (the current
 * production value) with NO country route configured. That combination
 * sends a USD booking at an INR-only provider, and the only acceptable
 * outcome is a refusal that leaves no obligation, no attempt, and no
 * gateway call behind.
 */
class InternationalPaymentRoutingTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private const STRIPE_SECRET = 'sk_test_intl123';

    private const STRIPE_PUBLISHABLE = 'pk_test_intl123';

    private const STRIPE_WEBHOOK_SECRET = 'whsec_test_intl123';

    private User $student;

    private User $teacher;

    /** @var array<string, Country> keyed by ISO2 */
    private array $countries = [];

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

        // One paid booking type; each market gets its own country,
        // currency, and price row — exactly how the pricing matrix is
        // seeded in production (StudentLessonPriceSeeder).
        $india = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR', 'IN');
        $this->countries['IN'] = $india['country'];

        $type = $india['type'];

        foreach ([['US', 'USD', '840', 49.00], ['GB', 'GBP', '826', 12.00]] as [$iso2, $code, $numeric, $amount]) {
            $currency = Currency::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $code, 'symbol' => $code, 'numeric_code' => $numeric, 'minor_units' => 2, 'status' => 'active'],
            );

            $country = Country::factory()->create(['iso2' => $iso2, 'default_currency_id' => $currency->id]);
            $this->seedStudentLessonPrice($type, $country, $currency, $amount);

            $this->countries[$iso2] = $country;
        }
    }

    // ── §11 Global default provider safety ────────────────────────────────

    /**
     * The live production configuration meeting its first foreign student.
     *
     * `default_provider = razorpay`, no country route for US. Resolution
     * legitimately lands on Razorpay; the currency guard is what has to
     * catch it. It runs BEFORE the booking-row transaction, so a refusal
     * here must leave the database exactly as it found it.
     */
    public function test_us_student_is_refused_safely_when_only_the_india_default_provider_is_configured(): void
    {
        $this->configureRazorpay();
        $this->settings(fn (PaymentGatewaySettings $s) => $s->default_provider = 'razorpay');

        // Strict mock, zero expectations: any gateway call fails the test.
        $this->instance(RazorpayGatewayClient::class, Mockery::mock(RazorpayGatewayClient::class));

        $booking = $this->bookAs('US');

        try {
            app(BookingPaymentServiceInterface::class)->initiate($booking);
            $this->fail('A USD booking must not be initiated against an INR-only provider.');
        } catch (BookingException $e) {
            // Razorpay International is not attested here, so USD is not
            // a collectable currency for this account — refused by the
            // market gate before the resolver's own guard is reached.
            $this->assertMatchesRegularExpression('/does not support USD|only supports/', $e->getMessage());
        }

        $this->assertNoMoneyState($booking);
    }

    public function test_uk_student_is_refused_safely_under_the_same_india_default(): void
    {
        $this->configureRazorpay();
        $this->settings(fn (PaymentGatewaySettings $s) => $s->default_provider = 'razorpay');
        $this->instance(RazorpayGatewayClient::class, Mockery::mock(RazorpayGatewayClient::class));

        $booking = $this->bookAs('GB');

        $this->expectException(BookingException::class);

        try {
            app(BookingPaymentServiceInterface::class)->initiate($booking);
        } finally {
            $this->assertNoMoneyState($booking);
        }
    }

    // ── §10 Country routing → capability → attempt ────────────────────────

    public function test_us_country_route_sends_a_usd_booking_to_stripe_with_exact_minor_units(): void
    {
        $this->configureStripe();
        $this->routeCountry('US', 'stripe');
        $this->fakeStripeIntent('pi_US1', amountMinor: 4900, currency: 'usd');

        $booking = $this->bookAs('US');
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $obligation = BookingPayment::query()->where('booking_id', $booking->id)->sole();

        $this->assertSame('stripe', $obligation->provider);
        $this->assertSame(4900, $obligation->amount_minor, '49.00 USD is 4900 minor units.');
        $this->assertSame('USD', $obligation->currency_code);

        // No conversion to INR anywhere in SIRI.
        $this->assertSame('USD', $booking->refresh()->currency);
    }

    public function test_uk_country_route_sends_a_gbp_booking_to_stripe_with_exact_minor_units(): void
    {
        $this->configureStripe();
        $this->routeCountry('GB', 'stripe');
        $this->fakeStripeIntent('pi_GB1', amountMinor: 1200, currency: 'gbp');

        $booking = $this->bookAs('GB');
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $obligation = BookingPayment::query()->where('booking_id', $booking->id)->sole();

        $this->assertSame('stripe', $obligation->provider);
        $this->assertSame(1200, $obligation->amount_minor, '12.00 GBP is 1200 minor units.');
        $this->assertSame('GBP', $obligation->currency_code);
    }

    /**
     * §12 — the complete international path, settled the only way
     * settlement is ever authoritative: a signed server-to-server webhook.
     */
    public function test_gbp_booking_settles_through_a_signed_stripe_webhook(): void
    {
        $this->configureStripe();
        $this->routeCountry('GB', 'stripe');
        $this->fakeStripeIntent('pi_GB2', amountMinor: 1200, currency: 'gbp');

        $booking = $this->bookAs('GB');
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $reference = $this->attemptReference($booking);

        $this->postStripeWebhook($this->intentPayload('pi_GB2', $reference, 1200, 'gbp'))
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);

        $attempt = Payment::query()->where('payable_id', BookingPayment::query()->where('booking_id', $booking->id)->value('id'))->sole();
        $this->assertSame(PaymentStatus::Paid, $attempt->status);
        $this->assertSame(1200, (int) $attempt->amount_minor);
        $this->assertSame('GBP', $attempt->currency_code, 'The settled attempt keeps its own historical currency.');

        // §14 — the receipt inherits the SETTLED currency, never a
        // platform default. MoneyFormatter is the only renderer, so
        // "12.00 GBP" is what every surface (list, PDF, email) shows.
        $invoice = Invoice::query()->sole();
        $this->assertSame(1200, (int) $invoice->amount_minor);
        $this->assertSame('GBP', $invoice->currency_code);
        $this->assertSame('12.00 GBP', MoneyFormatter::format($invoice->amount_minor, $invoice->currency_code));
        $this->assertStringNotContainsString('₹', MoneyFormatter::format($invoice->amount_minor, $invoice->currency_code));
    }

    /**
     * §14 — the receipt is history. Re-pointing the student at India and
     * flipping the platform default must not retro-denominate a GBP
     * payment that already settled.
     */
    public function test_a_settled_international_invoice_keeps_its_currency_after_the_student_moves_country(): void
    {
        $this->configureStripe();
        $this->routeCountry('GB', 'stripe');
        $this->fakeStripeIntent('pi_GB5', amountMinor: 1200, currency: 'gbp');

        $booking = $this->bookAs('GB');
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $this->postStripeWebhook($this->intentPayload('pi_GB5', $this->attemptReference($booking), 1200, 'gbp'))->assertOk();

        $invoice = Invoice::query()->sole();

        // The student relocates; the pricing matrix and default currency move with them.
        $this->assignBillingCountry($this->student, $this->countries['IN']);

        $this->assertSame('GBP', $invoice->refresh()->currency_code);
        $this->assertSame(1200, (int) $invoice->amount_minor);
        $this->assertSame('GBP', $booking->refresh()->currency);
    }

    // ── §16 Webhook currency/amount integrity, non-INR ────────────────────

    public function test_a_gbp_booking_refuses_a_webhook_reporting_inr(): void
    {
        $this->configureStripe();
        $this->routeCountry('GB', 'stripe');
        $this->fakeStripeIntent('pi_GB3', amountMinor: 1200, currency: 'gbp');

        $booking = $this->bookAs('GB');
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $this->postStripeWebhook($this->intentPayload('pi_GB3', $this->attemptReference($booking), 1200, 'inr'))
            ->assertOk()
            ->assertJsonPath('status', 'ignored');

        $this->assertNotPaid($booking);
    }

    public function test_a_gbp_booking_refuses_a_webhook_reporting_a_larger_amount(): void
    {
        $this->configureStripe();
        $this->routeCountry('GB', 'stripe');
        $this->fakeStripeIntent('pi_GB4', amountMinor: 1200, currency: 'gbp');

        $booking = $this->bookAs('GB');
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $this->postStripeWebhook($this->intentPayload('pi_GB4', $this->attemptReference($booking), 9900, 'gbp'))
            ->assertOk()
            ->assertJsonPath('status', 'ignored');

        $this->assertNotPaid($booking);
    }

    // ── §5 Server-authoritative pricing ───────────────────────────────────

    /**
     * The obligation is derived entirely from the seeded price matrix and
     * the student's billing country. There is no request-supplied amount,
     * currency, or provider anywhere in `initiate()` — this pins that by
     * proving the persisted figures match the price row, not the inflated
     * values a tampered client would send.
     */
    public function test_the_persisted_amount_currency_and_provider_come_from_the_server_not_the_request(): void
    {
        $this->configureStripe();
        $this->routeCountry('US', 'stripe');
        $this->fakeStripeIntent('pi_US2', amountMinor: 4900, currency: 'usd');

        $booking = $this->bookAs('US');

        // A tampered client would love these to matter. They cannot:
        // initiate() takes only the Booking.
        $this->actingAs($this->student)->post('/dummy-not-a-route', [
            'amount' => 1, 'currency' => 'INR', 'provider' => 'razorpay',
        ]);

        app(BookingPaymentServiceInterface::class)->initiate($booking->refresh());

        $obligation = BookingPayment::query()->where('booking_id', $booking->id)->sole();

        $this->assertSame(4900, $obligation->amount_minor);
        $this->assertSame('USD', $obligation->currency_code);
        $this->assertSame('stripe', $obligation->provider);
    }

    // ── §6 Minor units come from the Currency row ─────────────────────────

    public function test_each_supported_currency_derives_its_exponent_from_the_currency_table(): void
    {
        foreach (['INR', 'USD', 'GBP'] as $code) {
            $this->assertSame(
                (int) Currency::query()->where('code', $code)->value('minor_units'),
                MoneyFormatter::minorUnitsFor($code),
                sprintf('%s must take its exponent from currencies.minor_units.', $code),
            );
        }

        $this->assertSame(4900, MoneyFormatter::toMinor('49.00', MoneyFormatter::minorUnitsFor('USD')));
        $this->assertSame(1200, MoneyFormatter::toMinor('12.00', MoneyFormatter::minorUnitsFor('GBP')));
        $this->assertSame(49900, MoneyFormatter::toMinor('499.00', MoneyFormatter::minorUnitsFor('INR')));
    }

    // ── §17 Unsupported currency / missing route ──────────────────────────

    /**
     * EUR sits in Stripe's declared capability list but has no row in
     * SIRI's `currencies` table, so no EU market can be priced at all.
     *
     * Seeding it inactive — the closest an operator can get without a
     * deliberate activation — shows the gate holds one step earlier than
     * checkout: the booking itself is refused at price resolution, so
     * there is never a booking for `initiate()` to reject. Documents the
     * real boundary instead of implying EUR is live.
     */
    public function test_eur_is_not_a_supported_siri_currency_and_cannot_be_booked(): void
    {
        $this->configureStripe();
        $this->instance(StripeGatewayClient::class, Mockery::mock(StripeGatewayClient::class));

        $this->assertNull(
            Currency::query()->where('code', 'EUR')->first(),
            'EUR is deliberately not a supported SIRI currency in V1 (CurrencySeeder).',
        );

        $eur = Currency::query()->create([
            'code' => 'EUR', 'name' => 'Euro', 'symbol' => 'EUR', 'numeric_code' => '978',
            'minor_units' => 2, 'status' => 'inactive', 'sort_order' => 99,
        ]);
        $country = Country::factory()->create(['iso2' => 'DE', 'default_currency_id' => $eur->id]);
        $this->seedStudentLessonPrice(
            BookingType::query()->where('key', 'paid_one_to_one')->sole(),
            $country,
            $eur,
            30.00,
        );
        $this->routeCountry('DE', 'stripe');

        try {
            $this->bookAs('DE');
            $this->fail('A booking must not be created in a currency SIRI cannot collect.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('currently unavailable', $e->getMessage());
        }

        $this->assertSame(0, BookingPayment::query()->count());
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_routing_a_country_to_a_disabled_provider_fails_before_money_state(): void
    {
        // Stripe route configured, Stripe never enabled.
        $this->configureRazorpay();
        $this->routeCountry('US', 'stripe');
        $this->instance(StripeGatewayClient::class, Mockery::mock(StripeGatewayClient::class));

        $booking = $this->bookAs('US');

        $this->expectException(BookingException::class);

        try {
            app(BookingPaymentServiceInterface::class)->initiate($booking);
        } finally {
            $this->assertNoMoneyState($booking);
        }
    }

    // ── §18 India must stay green ─────────────────────────────────────────

    public function test_india_still_routes_to_razorpay_and_prices_in_inr(): void
    {
        $this->configureRazorpay();
        $this->routeCountry('IN', 'razorpay');

        $gateway = Mockery::mock(RazorpayGatewayClient::class);
        $gateway->shouldReceive('createOrder')->once()->andReturn(['id' => 'order_IN1']);
        $this->instance(RazorpayGatewayClient::class, $gateway);

        $booking = $this->bookAs('IN');
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $obligation = BookingPayment::query()->where('booking_id', $booking->id)->sole();

        $this->assertSame('razorpay', $obligation->provider);
        $this->assertSame(49900, $obligation->amount_minor);
        $this->assertSame('INR', $obligation->currency_code);
    }

    public function test_an_international_route_does_not_disturb_the_india_route(): void
    {
        $this->configureRazorpay();
        $this->routeCountry('US', 'stripe');
        $this->routeCountry('IN', 'razorpay');

        $gateway = Mockery::mock(RazorpayGatewayClient::class);
        $gateway->shouldReceive('createOrder')->once()->andReturn(['id' => 'order_IN2']);
        $this->instance(RazorpayGatewayClient::class, $gateway);

        $booking = $this->bookAs('IN');
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $this->assertSame('razorpay', BookingPayment::query()->where('booking_id', $booking->id)->value('provider'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function settings(callable $mutate): void
    {
        $settings = app(PaymentGatewaySettings::class);
        $mutate($settings);
        $settings->save();
    }

    private function configureRazorpay(): void
    {
        $this->settings(function (PaymentGatewaySettings $s): void {
            $s->payments_enabled = true;
            $s->razorpay_enabled = true;
            $s->razorpay_key_id = 'rzp_test_key_id';
            $s->razorpay_key_secret = Crypt::encryptString('rzp_secret');
            $s->razorpay_webhook_secret = Crypt::encryptString('rzp_webhook');
        });

        $bookings = app(BookingSettings::class);
        $bookings->payment_provider = 'razorpay';
        $bookings->save();
    }

    private function configureStripe(): void
    {
        $this->settings(function (PaymentGatewaySettings $s): void {
            $s->payments_enabled = true;
            $s->stripe_enabled = true;
            $s->stripe_publishable_key = self::STRIPE_PUBLISHABLE;
            $s->stripe_secret_key = Crypt::encryptString(self::STRIPE_SECRET);
            $s->stripe_webhook_secret = Crypt::encryptString(self::STRIPE_WEBHOOK_SECRET);
        });

        $bookings = app(BookingSettings::class);
        $bookings->payment_provider = 'stripe';
        $bookings->save();
    }

    private function routeCountry(string $iso2, string $provider): void
    {
        Country::query()->where('iso2', $iso2)->update([
            'payment_routing' => json_encode(['provider' => $provider, 'enabled' => true]),
        ]);
    }

    private function fakeStripeIntent(string $intentId, int $amountMinor, string $currency): void
    {
        $intent = ['id' => $intentId, 'client_secret' => $intentId.'_secret', 'amount' => $amountMinor, 'currency' => $currency];

        $gateway = Mockery::mock(StripeGatewayClient::class);
        $gateway->shouldReceive('createPaymentIntent')->andReturn($intent);
        $gateway->shouldReceive('retrievePaymentIntent')->andReturn($intent);

        $this->instance(StripeGatewayClient::class, $gateway);
    }

    private function bookAs(string $countryIso2): Booking
    {
        $this->assignBillingCountry($this->student, $this->countries[$countryIso2] ?? Country::query()->where('iso2', $countryIso2)->sole());

        $booking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ));

        return $booking->refresh();
    }

    private function attemptReference(Booking $booking): string
    {
        $obligationId = BookingPayment::query()->where('booking_id', $booking->id)->value('id');

        return (string) Payment::query()->where('payable_id', $obligationId)->value('idempotency_key');
    }

    /** @return array<string, mixed> */
    private function intentPayload(string $intentId, string $reference, int $amountMinor, string $currency): array
    {
        return [
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => $intentId,
                    'amount' => $amountMinor,
                    'amount_received' => $amountMinor,
                    'currency' => $currency,
                    'metadata' => ['payment_reference' => $reference],
                ],
            ],
        ];
    }

    private function postStripeWebhook(array $payload): TestResponse
    {
        $body = (string) json_encode($payload);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", self::STRIPE_WEBHOOK_SECRET);

        return $this->call('POST', '/api/webhooks/bookings/payments/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    /** A refusal must leave no obligation, no attempt, and no paid state. */
    private function assertNoMoneyState(Booking $booking): void
    {
        $this->assertSame(0, BookingPayment::query()->where('booking_id', $booking->id)->count(), 'No obligation may survive a refused checkout.');
        $this->assertSame(0, Payment::query()->count(), 'No payment attempt may survive a refused checkout.');
        $this->assertNotSame(BookingPaymentStatus::Paid, $booking->refresh()->payment_status);
    }

    private function assertNotPaid(Booking $booking): void
    {
        $booking->refresh();
        $this->assertNotSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $this->assertNotSame(BookingStatus::Confirmed, $booking->status);
    }
}
