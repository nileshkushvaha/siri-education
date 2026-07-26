<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\GatewayRequestException;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\BookingType;
use App\Models\Currency;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Settings\BookingSettings;
use App\Settings\PaymentGatewaySettings;
use App\Wallet\Enums\WalletLedgerEntryType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

class StripeCheckoutTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private const SECRET_KEY = 'sk_test_abc123';

    private const PUBLISHABLE_KEY = 'pk_test_abc123';

    private const WEBHOOK_SECRET = 'whsec_test_abc123';

    private User $student;

    private User $teacher;

    private StripeGatewayClient&MockInterface $stripeGateway;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'numeric_code' => '840', 'minor_units' => 2, 'status' => 'active']);

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $this->student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);

        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $this->teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        // BookingType::factory()->paid() does not carry a price —
        // createPaidBookingTypeWithPrice() also seeds the matching
        // StudentLessonPrice (USD, all levels, 60min).
        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 49.00, 'USD');
        $this->assignBillingCountry($this->student, $priced['country']);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function configureStripe(bool $enabled = true): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->stripe_enabled = $enabled;
        $gateways->stripe_publishable_key = self::PUBLISHABLE_KEY;
        $gateways->stripe_secret_key = Crypt::encryptString(self::SECRET_KEY);
        $gateways->stripe_webhook_secret = Crypt::encryptString(self::WEBHOOK_SECRET);
        $gateways->save();

        app(BookingSettings::class)->payment_provider = 'stripe';
        app(BookingSettings::class)->save();
    }

    /** Binds a fake StripeGatewayClient — the seam the SDK is isolated behind — instead of stubbing HTTP/cURL. */
    private function fakeStripeIntentApi(string $intentId = 'pi_TEST123', string $clientSecret = 'pi_TEST123_secret_xyz'): void
    {
        $this->stripeGateway = Mockery::mock(StripeGatewayClient::class);
        $this->stripeGateway->shouldReceive('createPaymentIntent')
            ->andReturn(['id' => $intentId, 'client_secret' => $clientSecret, 'amount' => 4900, 'currency' => 'usd']);
        $this->stripeGateway->shouldReceive('retrievePaymentIntent')
            ->andReturn(['id' => $intentId, 'client_secret' => $clientSecret, 'amount' => 4900, 'currency' => 'usd']);

        $this->app->instance(StripeGatewayClient::class, $this->stripeGateway);
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

    private function paymentReference(Booking $booking): string
    {
        return (string) $booking->refresh()->payment_reference;
    }

    private function webhookSignature(string $body, int $timestamp): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$body}", self::WEBHOOK_SECRET);
    }

    private function postWebhook(array $payload): TestResponse
    {
        $body = (string) json_encode($payload);
        $timestamp = time();
        $signature = $this->webhookSignature($body, $timestamp);

        return $this->call('POST', '/api/webhooks/bookings/payments/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    private function intentPayload(string $event, string $intentId, string $reference, int $amount = 4900, string $currency = 'usd'): array
    {
        return [
            'type' => $event,
            'data' => [
                'object' => [
                    'id' => $intentId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'metadata' => ['booking_reference' => $reference],
                ],
            ],
        ];
    }

    // ── A. Provider / config ─────────────────────────────────────────

    public function test_stripe_provider_is_registered_and_selected_via_booking_settings(): void
    {
        $this->configureStripe();
        $this->fakeStripeIntentApi();

        $booking = $this->reserveStudent();
        $intent = app(BookingPaymentServiceInterface::class)->initiate($booking);

        $this->assertNotNull(BookingPayment::query()->where('booking_id', $booking->id)->first());
        $this->assertSame($booking->id, $intent->bookingId);
        $this->assertSame(self::PUBLISHABLE_KEY, $intent->publicKey);
        $this->assertNotNull($intent->clientSecret);
    }

    public function test_stripe_rejects_random_unformatted_credentials(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->stripe_enabled = true;
        $gateways->stripe_publishable_key = 'not-a-real-key';
        $gateways->stripe_secret_key = Crypt::encryptString('totally-random-text');
        $gateways->save();
        app(BookingSettings::class)->payment_provider = 'stripe';
        app(BookingSettings::class)->save();

        $booking = $this->reserveStudent();

        $this->expectException(BookingException::class);
        app(BookingPaymentServiceInterface::class)->initiate($booking);
    }

    public function test_stripe_requires_gateway_enabled_before_intent_creation(): void
    {
        $this->configureStripe(enabled: false);
        $this->fakeStripeIntentApi();

        $booking = $this->reserveStudent();

        $this->expectException(BookingException::class);
        app(BookingPaymentServiceInterface::class)->initiate($booking);
    }

    public function test_stripe_amounts_are_integer_minor_units(): void
    {
        $this->configureStripe();
        $this->fakeStripeIntentApi();

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $payment = BookingPayment::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertIsInt($payment->amount_minor);
        $this->assertSame(4900, $payment->amount_minor);
    }

    public function test_stripe_secret_key_is_never_exposed_via_checkout_payload(): void
    {
        $this->configureStripe();
        $this->fakeStripeIntentApi();

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $payload = app(BookingPaymentServiceInterface::class)->checkoutPayload($booking);

        $this->assertArrayHasKey('client_secret', $payload);
        $this->assertArrayHasKey('publishable_key', $payload);
        $this->assertArrayNotHasKey('secret_key', $payload);
        $this->assertArrayNotHasKey('webhook_secret', $payload);
        $this->assertSame(self::PUBLISHABLE_KEY, $payload['publishable_key']);
    }

    // ── B. Order creation ─────────────────────────────────────────────

    public function test_stripe_intent_creation_is_idempotent_on_repeated_initiate(): void
    {
        $this->configureStripe();

        $this->stripeGateway = Mockery::mock(StripeGatewayClient::class);
        $this->stripeGateway->shouldReceive('createPaymentIntent')
            ->once() // second initiate() reuses the row without a create call
            ->andReturn(['id' => 'pi_TEST123', 'client_secret' => 'secret', 'amount' => 4900, 'currency' => 'usd']);
        $this->app->instance(StripeGatewayClient::class, $this->stripeGateway);

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        app(BookingPaymentServiceInterface::class)->initiate($booking->refresh());

        $this->assertSame(1, BookingPayment::query()->where('booking_id', $booking->id)->count());
    }

    public function test_stripe_intent_creation_does_not_mark_booking_paid_or_create_meeting(): void
    {
        $this->configureStripe();
        $this->fakeStripeIntentApi();

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status);
        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertNull($booking->meeting_url);
        $this->assertNull($booking->meeting_ref);
    }

    public function test_stripe_intent_creation_failure_marks_payment_row_failed(): void
    {
        $this->configureStripe();

        $mock = Mockery::mock(StripeGatewayClient::class);
        $mock->shouldReceive('createPaymentIntent')->andThrow(new GatewayRequestException('bad request'));
        $this->app->instance(StripeGatewayClient::class, $mock);

        $booking = $this->reserveStudent();

        try {
            app(BookingPaymentServiceInterface::class)->initiate($booking);
            $this->fail('Expected a BookingException.');
        } catch (BookingException) {
            // expected
        }

        $this->assertDatabaseHas('booking_payments', ['booking_id' => $booking->id, 'status' => 'failed']);
    }

    // ── C. Webhook verification ───────────────────────────────────────

    public function test_stripe_webhook_payment_intent_succeeded_settles_payment_and_confirms_booking(): void
    {
        $this->configureStripe();
        $this->fakeStripeIntentApi('pi_SUCC1', 'pi_SUCC1_secret');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);

        BookingPayment::query()->where('booking_id', $booking->id)->update(['provider_order_id' => 'pi_SUCC1']);

        $response = $this->postWebhook($this->intentPayload('payment_intent.succeeded', 'pi_SUCC1', $reference));

        $response->assertOk()->assertJson(['status' => 'processed']);

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
    }

    public function test_stripe_webhook_signature_invalid_is_rejected_without_processing(): void
    {
        $this->configureStripe();
        $this->fakeStripeIntentApi('pi_BAD1', 'pi_BAD1_secret');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);
        BookingPayment::query()->where('booking_id', $booking->id)->update(['provider_order_id' => 'pi_BAD1']);

        $body = (string) json_encode($this->intentPayload('payment_intent.succeeded', 'pi_BAD1', $reference));
        $response = $this->call('POST', '/api/webhooks/bookings/payments/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=forged',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);

        $response->assertStatus(401);

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status);
    }

    public function test_stripe_webhook_amount_mismatch_fails_safely(): void
    {
        $this->configureStripe();
        $this->fakeStripeIntentApi('pi_AMT1', 'pi_AMT1_secret');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);
        BookingPayment::query()->where('booking_id', $booking->id)->update(['provider_order_id' => 'pi_AMT1']);

        $response = $this->postWebhook($this->intentPayload('payment_intent.succeeded', 'pi_AMT1', $reference, amount: 999999));

        $response->assertStatus(401);

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status);
    }

    public function test_stripe_webhook_currency_mismatch_fails_safely(): void
    {
        $this->configureStripe();
        $this->fakeStripeIntentApi('pi_CUR1', 'pi_CUR1_secret');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);
        BookingPayment::query()->where('booking_id', $booking->id)->update(['provider_order_id' => 'pi_CUR1']);

        $response = $this->postWebhook($this->intentPayload('payment_intent.succeeded', 'pi_CUR1', $reference, currency: 'eur'));

        $response->assertStatus(401);

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status);
    }

    public function test_stripe_webhook_payment_intent_failed_keeps_reservation_for_retry(): void
    {
        $this->configureStripe();
        $this->fakeStripeIntentApi('pi_FAIL1', 'pi_FAIL1_secret');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);
        BookingPayment::query()->where('booking_id', $booking->id)->update(['provider_order_id' => 'pi_FAIL1']);

        $response = $this->postWebhook($this->intentPayload('payment_intent.payment_failed', 'pi_FAIL1', $reference));

        $response->assertOk()->assertJson(['status' => 'processed']);

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Failed, $booking->payment_status);
        $this->assertSame(BookingStatus::Pending, $booking->status);
    }

    public function test_stripe_webhook_duplicate_delivery_is_idempotent(): void
    {
        $this->configureStripe();
        $this->fakeStripeIntentApi('pi_DUP1', 'pi_DUP1_secret');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);
        BookingPayment::query()->where('booking_id', $booking->id)->update(['provider_order_id' => 'pi_DUP1']);

        $payload = $this->intentPayload('payment_intent.succeeded', 'pi_DUP1', $reference);
        $this->postWebhook($payload)->assertOk()->assertJson(['status' => 'processed']);
        $this->postWebhook($payload)->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(1, BookingPayment::query()->where('booking_id', $booking->id)->count());
    }

    // ── Option B: late success on a terminal booking ────────────────────

    public function test_cancelled_booking_late_stripe_success_credits_wallet_and_does_not_confirm(): void
    {
        $this->configureStripe();
        $this->fakeStripeIntentApi('pi_LATE1', 'pi_LATE1_secret');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);
        BookingPayment::query()->where('booking_id', $booking->id)->update(['provider_order_id' => 'pi_LATE1']);

        $booking = app(BookingServiceInterface::class)->cancel($booking, new CancelBookingData(
            BookingActor::System,
            'Payment was not completed within the reservation window.',
        ));

        $response = $this->postWebhook($this->intentPayload('payment_intent.succeeded', 'pi_LATE1', $reference));

        $response->assertOk()->assertJson(['status' => 'processed']);

        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame(BookingPaymentStatus::Refunded, $booking->payment_status);

        $wallet = Wallet::query()->where('user_id', $this->student->id)->firstOrFail();
        $this->assertSame(4900, $wallet->balance_minor);

        $entry = WalletLedgerEntry::query()->where('wallet_id', $wallet->id)->firstOrFail();
        $this->assertSame(WalletLedgerEntryType::LatePaymentCredit, $entry->entry_type);
    }

    public function test_duplicate_late_stripe_webhook_does_not_duplicate_wallet_credit(): void
    {
        $this->configureStripe();
        $this->fakeStripeIntentApi('pi_LATE2', 'pi_LATE2_secret');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);
        BookingPayment::query()->where('booking_id', $booking->id)->update(['provider_order_id' => 'pi_LATE2']);

        app(BookingServiceInterface::class)->cancel($booking, new CancelBookingData(
            BookingActor::System,
            'Payment was not completed within the reservation window.',
        ));

        $payload = $this->intentPayload('payment_intent.succeeded', 'pi_LATE2', $reference);
        $this->postWebhook($payload)->assertOk()->assertJson(['status' => 'processed']);
        $this->postWebhook($payload)->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(1, WalletLedgerEntry::query()->count());
    }

    // ── D. Boundaries ──────────────────────────────────────────────────

    public function test_successful_stripe_payment_never_creates_wallet_or_ledger_rows(): void
    {
        $this->configureStripe();
        $this->fakeStripeIntentApi('pi_WAL1', 'pi_WAL1_secret');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);
        BookingPayment::query()->where('booking_id', $booking->id)->update(['provider_order_id' => 'pi_WAL1']);

        $this->postWebhook($this->intentPayload('payment_intent.succeeded', 'pi_WAL1', $reference));

        $this->assertSame(0, Wallet::query()->count());
        $this->assertSame(0, WalletLedgerEntry::query()->count());
    }

    public function test_active_stripe_refund_calls_stripe_and_cancels_booking(): void
    {
        $this->configureStripe();
        $this->fakeStripeIntentApi('pi_REF1', 'pi_REF1_secret');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);
        BookingPayment::query()->where('booking_id', $booking->id)->update(['provider_order_id' => 'pi_REF1']);

        $this->postWebhook($this->intentPayload('payment_intent.succeeded', 'pi_REF1', $reference));
        BookingPayment::query()->where('booking_id', $booking->id)->update(['provider_payment_id' => 'pi_REF1']);

        $this->stripeGateway->shouldReceive('createRefund')
            ->once()
            ->andReturn(['id' => 're_1', 'status' => 'succeeded']);

        $booking->refresh();
        $financeAdmin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        app(BookingPaymentServiceInterface::class)->refundViaProvider($booking, $financeAdmin, 'Test refund');

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Refunded, $booking->payment_status);
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
    }

    public function test_fake_provider_flow_is_unaffected_by_stripe_registration(): void
    {
        app(BookingSettings::class)->payment_provider = 'fake';
        app(BookingSettings::class)->save();

        $booking = $this->reserveStudent();
        $intent = app(BookingPaymentServiceInterface::class)->initiate($booking);

        $this->assertSame('pending', $intent->status);
        // The fake provider creates its own (Pending, then Captured on
        // webhook success) BookingPayment row too, matching every real
        // adapter.
        $this->assertSame(1, BookingPayment::query()->count());
        $this->assertSame('pending', BookingPayment::sole()->status->value);
    }
}
