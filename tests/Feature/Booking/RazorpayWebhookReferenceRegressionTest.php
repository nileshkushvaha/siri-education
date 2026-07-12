<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Booking\Registry\PaymentProviderRegistry;
use App\Booking\Services\PaymentProviderResolver;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\BookingSettings;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * Phase 16C.1 — regression coverage for the real bug found and fixed
 * this phase: RazorpayPaymentProvider::createPayment() sent
 * $booking->reference (the human "BK-…" reference) in the order's
 * `notes.booking_reference`, but parseWebhook() hands that value
 * straight to BookingRepository::findByPaymentReference(), which
 * queries the `payment_reference` column ("PAY-…"). A webhook arriving
 * without a prior verifyCheckout() (the client tab closed before
 * checkout.js's success callback fired) would report "unknown
 * reference" and never settle. Unlike RazorpayCheckoutTest.php's
 * webhook tests (which hand-build a "correct" payload using the
 * payment reference directly, sidestepping the bug), these tests
 * capture the REAL metadata createOrder() is called with and build the
 * webhook from that captured value — proving the actual round trip.
 */
class RazorpayWebhookReferenceRegressionTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private const KEY_SECRET = 'test_key_secret_regress';

    private const WEBHOOK_SECRET = 'test_webhook_secret_regress';

    private User $student;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $this->teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR');
        $this->assignBillingCountry($this->student, $priced['country']);

        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString(self::KEY_SECRET);
        $gateways->razorpay_webhook_secret = Crypt::encryptString(self::WEBHOOK_SECRET);
        $gateways->save();
        app(BookingSettings::class)->payment_provider = 'razorpay';
        app(BookingSettings::class)->save();
    }

    private function reserveStudent(): Booking
    {
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

    /**
     * Initiates a real Razorpay order and captures the exact `notes`
     * payload createOrder() was called with — the metadata a real
     * webhook's payload would echo back, never hand-crafted by the test.
     *
     * @return array{0: Booking, 1: string, 2: array<string, mixed>} booking, order id, captured notes
     */
    private function initiateAndCaptureOrderMetadata(): array
    {
        $booking = $this->reserveStudent();
        $orderId = 'order_REGRESS_'.bin2hex(random_bytes(4));
        $capturedNotes = [];

        $mock = Mockery::mock(RazorpayGatewayClient::class);
        $mock->shouldReceive('createOrder')
            ->withArgs(function (string $keyId, string $keySecret, array $params) use (&$capturedNotes): bool {
                $capturedNotes = $params['notes'] ?? [];

                return true;
            })
            ->andReturn(['id' => $orderId, 'amount' => 49900, 'currency' => 'INR']);
        $this->app->instance(RazorpayGatewayClient::class, $mock);

        app(BookingPaymentServiceInterface::class)->initiate($booking);

        return [$booking->refresh(), $orderId, $capturedNotes];
    }

    private function webhookSignature(string $body): string
    {
        return hash_hmac('sha256', $body, self::WEBHOOK_SECRET);
    }

    private function postWebhook(array $payload): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', '/api/webhooks/bookings/payments/razorpay', [], [], [], [
            'HTTP_X_RAZORPAY_SIGNATURE' => $this->webhookSignature($body),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    /** @param array<string, mixed> $notes */
    private function capturedPayload(string $orderId, string $paymentId, array $notes, int $amount = 49900, string $currency = 'INR', string $event = 'payment.captured'): array
    {
        return [
            'event' => $event,
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => $paymentId,
                        'order_id' => $orderId,
                        'amount' => $amount,
                        'currency' => $currency,
                        'method' => 'upi',
                        'notes' => $notes,
                    ],
                ],
            ],
        ];
    }

    public function test_order_created_and_webhook_uses_the_real_provider_metadata_settles_when_checkout_callback_never_arrives(): void
    {
        [$booking, $orderId, $notes] = $this->initiateAndCaptureOrderMetadata();

        // The exact value a real Razorpay webhook would echo back — never
        // hand-set by this test — must be the payment reference, and
        // findByPaymentReference() must locate this booking with it.
        $this->assertSame($booking->payment_reference, $notes['booking_reference'] ?? null);

        // Settlement arrives purely via webhook — verifyCheckout() (the
        // client-side checkout.js callback) is deliberately never called.
        $response = $this->postWebhook($this->capturedPayload($orderId, 'pay_regress_001', $notes));
        $response->assertOk()->assertJson(['status' => 'processed']);

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);

        $payment = BookingPayment::query()->where('booking_id', $booking->id)->sole();
        $this->assertSame(BookingPaymentRecordStatus::Captured, $payment->status);
    }

    public function test_duplicate_webhook_delivery_is_idempotent(): void
    {
        [$booking, $orderId, $notes] = $this->initiateAndCaptureOrderMetadata();
        $payload = $this->capturedPayload($orderId, 'pay_regress_002', $notes);

        $this->postWebhook($payload)->assertOk();
        $second = $this->postWebhook($payload);

        $second->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(1, BookingPayment::query()->where('booking_id', $booking->id)->count());
        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
    }

    public function test_a_reference_that_matches_no_known_payment_is_rejected_as_unknown(): void
    {
        [$booking, $orderId] = $this->initiateAndCaptureOrderMetadata();

        $forgedPayload = $this->capturedPayload($orderId, 'pay_regress_003', ['booking_reference' => 'PAY-DOES-NOT-EXIST']);

        $response = $this->postWebhook($forgedPayload);
        $response->assertOk()->assertJson(['status' => 'ignored', 'reason' => 'unknown reference']);

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status);
    }

    public function test_amount_mismatch_still_blocks_settlement_with_the_real_metadata(): void
    {
        [$booking, $orderId, $notes] = $this->initiateAndCaptureOrderMetadata();

        $tamperedPayload = $this->capturedPayload($orderId, 'pay_regress_004', $notes, amount: 1);

        $this->postWebhook($tamperedPayload)->assertStatus(401);

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status);
    }

    public function test_currency_mismatch_still_blocks_settlement_with_the_real_metadata(): void
    {
        [$booking, $orderId, $notes] = $this->initiateAndCaptureOrderMetadata();

        $tamperedPayload = $this->capturedPayload($orderId, 'pay_regress_005', $notes, currency: 'USD');

        $this->postWebhook($tamperedPayload)->assertStatus(401);

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status);
    }

    public function test_stripe_and_razorpay_send_the_same_canonical_payment_reference_in_their_webhook_metadata(): void
    {
        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'numeric_code' => '840', 'minor_units' => 2, 'status' => 'active']);

        // Razorpay side (INR, from setUp()'s fixtures).
        [$razorpayBooking, , $razorpayNotes] = $this->initiateAndCaptureOrderMetadata();

        // Stripe side — same booking_types.key ('paid_one_to_one' is the
        // only registered key BookingTypeRegistry recognizes; the column
        // is globally unique, so a second row is never created here),
        // separate student/country/currency, same canonical rule.
        $stripeStudent = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $sharedType = BookingType::query()->where('key', 'paid_one_to_one')->firstOrFail();
        $usd = Currency::query()->where('code', 'USD')->firstOrFail();
        $usdCountry = Country::factory()->create(['default_currency_id' => $usd->id]);
        $this->seedStudentLessonPrice($sharedType, $usdCountry, $usd, 49.00);
        $this->assignBillingCountry($stripeStudent, $usdCountry);

        $gateways = app(PaymentGatewaySettings::class);
        $gateways->stripe_enabled = true;
        $gateways->stripe_publishable_key = 'pk_test_regress123';
        $gateways->stripe_secret_key = Crypt::encryptString('sk_test_regress123');
        $gateways->stripe_webhook_secret = Crypt::encryptString('whsec_regress123');
        $gateways->save();
        app(BookingSettings::class)->payment_provider = 'stripe';
        app(BookingSettings::class)->save();

        // PaymentProviderRegistry AND PaymentProviderResolver are both
        // singletons — the Razorpay portion above already resolved both,
        // which baked in whatever StripeGatewayClient was bound at that
        // moment (the real SDK client) into the registry's StripePaymentProvider,
        // and PaymentProviderResolver's own singleton instance separately
        // cached a reference to that same stale registry. Forgetting only
        // the registry is not enough — the resolver's cached reference
        // survives. Both must be forgotten so a fresh chain (fresh
        // resolver → fresh registry → fresh StripePaymentProvider) is
        // built once the mock below is bound.
        $this->app->forgetInstance(PaymentProviderRegistry::class);
        $this->app->forgetInstance(PaymentProviderResolver::class);

        $capturedMetadata = [];
        $stripeMock = Mockery::mock(StripeGatewayClient::class);
        $stripeMock->shouldReceive('createPaymentIntent')
            ->withArgs(function (string $secretKey, array $params, string $idempotencyKey) use (&$capturedMetadata): bool {
                $capturedMetadata = $params['metadata'] ?? [];

                return true;
            })
            ->andReturn(['id' => 'pi_regress_cross', 'client_secret' => 'pi_regress_cross_secret', 'amount' => 4900, 'currency' => 'usd']);
        $this->app->instance(StripeGatewayClient::class, $stripeMock);

        $stripeBooking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: $sharedType->key,
            studentId: $stripeStudent->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(5)->setTime(10, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ));
        app(BookingPaymentServiceInterface::class)->initiate($stripeBooking->refresh());
        $stripeBooking->refresh();

        // Same canonical rule on both providers: the metadata's
        // "booking_reference" is always the PAYMENT reference, never the
        // booking's own human reference.
        $this->assertSame($razorpayBooking->payment_reference, $razorpayNotes['booking_reference']);
        $this->assertSame($stripeBooking->payment_reference, $capturedMetadata['booking_reference']);
        $this->assertNotSame($razorpayBooking->reference, $razorpayNotes['booking_reference']);
        $this->assertNotSame($stripeBooking->reference, $capturedMetadata['booking_reference']);
    }
}
