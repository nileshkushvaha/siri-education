<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Payment;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\BookingSettings;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * PaymentProviderResolver's country-aware routing wired into
 * BookingPaymentService::initiate()/checkoutPayload().
 */
class CountryAwareProviderResolutionTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::query()->firstOrCreate(['code' => 'INR'], ['name' => 'Indian Rupee', 'symbol' => '₹', 'numeric_code' => '356', 'minor_units' => 2, 'status' => 'active']);
        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'numeric_code' => '840', 'minor_units' => 2, 'status' => 'active']);
        Currency::query()->firstOrCreate(['code' => 'GBP'], ['name' => 'British Pound', 'symbol' => '£', 'numeric_code' => '826', 'minor_units' => 2, 'status' => 'active']);

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $this->teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }
    }

    private function configureRazorpay(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString('secret');
        $gateways->razorpay_webhook_secret = Crypt::encryptString('whsecret');
        $gateways->save();
    }

    private function configureStripe(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->stripe_enabled = true;
        $gateways->stripe_publishable_key = 'pk_test_abc123';
        $gateways->stripe_secret_key = Crypt::encryptString('sk_test_abc123');
        $gateways->stripe_webhook_secret = Crypt::encryptString('whsec_abc123');
        $gateways->save();
    }

    private function studentInCountry(string $iso2, ?array $paymentRouting): User
    {
        $country = Country::factory()->create(['iso2' => $iso2, 'payment_routing' => $paymentRouting]);
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $student->id], ['country_id' => $country->id]);

        return $student;
    }

    private function bookAndInitiate(User $student, string $typeKey, float $price, string $currency): Booking
    {
        $type = BookingType::factory()->paid()->create([
            'key' => $typeKey,
            'duration_minutes' => 60,
        ]);

        // BookingType::factory()->paid() does not carry a price — seed a
        // matching StudentLessonPrice for this exact student's own
        // billing country (set by studentInCountry()).
        $currencyModel = Currency::query()->firstOrCreate(
            ['code' => $currency],
            ['name' => $currency, 'symbol' => $currency, 'numeric_code' => '000', 'minor_units' => 2, 'status' => 'active'],
        );
        $this->seedStudentLessonPrice($type, $student->profile->country, $currencyModel, $price);

        $booking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: $typeKey,
            studentId: $student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ))->refresh();

        app(BookingPaymentServiceInterface::class)->initiate($booking);

        return $booking->refresh();
    }

    public function test_student_in_india_routes_to_razorpay_when_country_routing_configured(): void
    {
        $this->configureRazorpay();
        $this->configureStripe();
        app(BookingSettings::class)->payment_provider = 'stripe'; // legacy knob deliberately says the opposite
        app(BookingSettings::class)->save();

        $student = $this->studentInCountry('IN', ['provider' => 'razorpay', 'enabled' => true]);

        $mock = Mockery::mock(RazorpayGatewayClient::class);
        $mock->shouldReceive('createOrder')->andReturn(['id' => 'order_IN1', 'amount' => 49900, 'currency' => 'INR']);
        $this->app->instance(RazorpayGatewayClient::class, $mock);

        $booking = $this->bookAndInitiate($student, 'paid_one_to_one', 499.00, 'INR');

        $payment = BookingPayment::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame('razorpay', $payment->provider);
    }

    public function test_student_in_us_routes_to_stripe_when_country_routing_configured(): void
    {
        $this->configureRazorpay();
        $this->configureStripe();
        app(BookingSettings::class)->payment_provider = 'razorpay'; // legacy knob deliberately says the opposite
        app(BookingSettings::class)->save();

        $student = $this->studentInCountry('US', ['provider' => 'stripe', 'enabled' => true]);

        $mock = Mockery::mock(StripeGatewayClient::class);
        $mock->shouldReceive('createPaymentIntent')->andReturn(['id' => 'pi_US1', 'client_secret' => 'secret', 'amount' => 4900, 'currency' => 'usd']);
        $this->app->instance(StripeGatewayClient::class, $mock);

        $booking = $this->bookAndInitiate($student, 'paid_one_to_one', 49.00, 'USD');

        $payment = BookingPayment::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame('stripe', $payment->provider);
    }

    public function test_student_in_uk_routes_to_stripe_when_country_routing_configured(): void
    {
        $this->configureStripe();

        $student = $this->studentInCountry('GB', ['provider' => 'stripe', 'enabled' => true]);

        $mock = Mockery::mock(StripeGatewayClient::class);
        $mock->shouldReceive('createPaymentIntent')->andReturn(['id' => 'pi_GB1', 'client_secret' => 'secret', 'amount' => 3900, 'currency' => 'gbp']);
        $this->app->instance(StripeGatewayClient::class, $mock);

        $booking = $this->bookAndInitiate($student, 'paid_one_to_one', 39.00, 'GBP');

        $payment = BookingPayment::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame('stripe', $payment->provider);
    }

    public function test_country_provider_not_ready_fails_safely(): void
    {
        // Razorpay routed but never configured/enabled.
        $student = $this->studentInCountry('IN', ['provider' => 'razorpay', 'enabled' => true]);

        $type = BookingType::factory()->paid()->create([
            'key' => 'paid_one_to_one',
            'duration_minutes' => 60,
        ]);
        $currency = Currency::query()->firstOrCreate(['code' => 'INR'], ['name' => 'INR', 'symbol' => '₹', 'numeric_code' => '356', 'minor_units' => 2, 'status' => 'active']);
        $this->seedStudentLessonPrice($type, $student->profile->country, $currency, 499.00);

        $booking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ))->refresh();

        $this->expectException(BookingException::class);
        app(BookingPaymentServiceInterface::class)->initiate($booking);
    }

    /**
     * A student with no billing country belongs to no market.
     *
     * This previously fell through to the platform default provider and
     * collected. Since Country.status became the canonical market gate,
     * "no country" is refused for the same reason an unlaunched country
     * is: there is no market to price, route, or collect in. The
     * fallback still exists in the resolver, but the market gate is
     * reached first — and refusal happens before any money state.
     */
    public function test_null_country_cannot_collect_because_it_belongs_to_no_market(): void
    {
        $this->configureRazorpay();
        app(BookingSettings::class)->payment_provider = 'razorpay';
        app(BookingSettings::class)->save();

        // Student with no country at payment-routing time — booking
        // *creation* still needs a resolvable country for pricing (Phase
        // 10.2D), so the country is set for creation and cleared only
        // before initiate(), which is what this test actually exercises.
        $student = $this->studentInCountry('IN', null);

        $mock = Mockery::mock(RazorpayGatewayClient::class);
        $mock->shouldReceive('createOrder')->andReturn(['id' => 'order_NULL1', 'amount' => 49900, 'currency' => 'INR']);
        $this->app->instance(RazorpayGatewayClient::class, $mock);

        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one', 'duration_minutes' => 60]);
        $currency = Currency::query()->firstOrCreate(['code' => 'INR'], ['name' => 'INR', 'symbol' => '₹', 'numeric_code' => '356', 'minor_units' => 2, 'status' => 'active']);
        $this->seedStudentLessonPrice($type, $student->profile->country, $currency, 499.00);

        $booking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ))->refresh();

        UserProfile::updateOrCreate(['user_id' => $student->id], ['country_id' => null]);

        try {
            app(BookingPaymentServiceInterface::class)->initiate($booking);
            $this->fail('A student with no billing country must not be able to collect.');
        } catch (BookingException $e) {
            $this->assertStringContainsString('not available in your country', $e->getMessage());
        }

        $this->assertSame(0, BookingPayment::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_random_razorpay_credentials_still_fail_safely_with_country_routing(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'totally-random-not-a-key';
        $gateways->razorpay_key_secret = Crypt::encryptString('secret');
        $gateways->save();

        $student = $this->studentInCountry('IN', ['provider' => 'razorpay', 'enabled' => true]);

        $type = BookingType::factory()->paid()->create([
            'key' => 'paid_one_to_one',
            'duration_minutes' => 60,
        ]);
        $currency = Currency::query()->firstOrCreate(['code' => 'INR'], ['name' => 'INR', 'symbol' => '₹', 'numeric_code' => '356', 'minor_units' => 2, 'status' => 'active']);
        $this->seedStudentLessonPrice($type, $student->profile->country, $currency, 499.00);

        $booking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ))->refresh();

        $this->expectException(BookingException::class);
        app(BookingPaymentServiceInterface::class)->initiate($booking);
    }
}
