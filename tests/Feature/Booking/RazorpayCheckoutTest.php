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
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Exceptions\InvalidPaymentWebhookException;
use App\Booking\Payments\RazorpayPaymentProvider;
use App\Filament\Resources\BookingPayments\BookingPaymentResource;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Settings\BookingSettings;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

class RazorpayCheckoutTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private const KEY_SECRET = 'test_key_secret';

    private const WEBHOOK_SECRET = 'test_webhook_secret';

    private User $student;

    private User $teacher;

    private RazorpayGatewayClient&MockInterface $razorpayGateway;

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

        // Phase 10.2D-Cleanup-Fix: BookingType::factory()->paid() no
        // longer carries a price — createPaidBookingTypeWithPrice() also
        // seeds the matching StudentLessonPrice (INR, all levels, 60min).
        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR');
        $this->assignBillingCountry($this->student, $priced['country']);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function configureRazorpay(bool $enabled = true): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = $enabled;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString(self::KEY_SECRET);
        $gateways->razorpay_webhook_secret = Crypt::encryptString(self::WEBHOOK_SECRET);
        $gateways->save();

        app(BookingSettings::class)->payment_provider = 'razorpay';
        app(BookingSettings::class)->save();
    }

    /** Binds a fake RazorpayGatewayClient — the seam the SDK is isolated behind — instead of stubbing HTTP/cURL. */
    private function fakeRazorpayOrderApi(string $orderId = 'order_TEST123'): void
    {
        $this->razorpayGateway = Mockery::mock(RazorpayGatewayClient::class);
        $this->razorpayGateway->shouldReceive('createOrder')
            ->andReturn(['id' => $orderId, 'amount' => 49900, 'currency' => 'INR']);

        $this->app->instance(RazorpayGatewayClient::class, $this->razorpayGateway);
    }

    private function reserveStudent(int $daysAhead = 3, int $hour = 10): Booking
    {
        $booking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays($daysAhead)->setTime($hour, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ));

        return $booking->refresh();
    }

    /**
     * A legacy guest booking, as if created before Phase 10.2C-Fix's "no
     * guest booking" rule shipped — built directly, since
     * GuestBookingServiceInterface::book() can no longer produce one. The
     * token-authorized payment routes stay reachable for bookings like
     * this so an already-reserved guest booking's payment isn't stranded.
     *
     * @return array{0: Booking, 1: string} booking + plain manage token
     */
    private function reserveGuest(int $daysAhead = 4, int $hour = 11): array
    {
        $plainToken = Str::random(64);

        $booking = Booking::factory()->create([
            'booking_type_id' => BookingType::query()->where('key', 'paid_one_to_one')->firstOrFail()->id,
            'attendee_id' => null,
            'host_id' => $this->teacher->id,
            'status' => BookingStatus::Pending,
            'payment_status' => BookingPaymentStatus::Pending,
            'price' => 499.00,
            'currency' => 'INR',
            'guest_name' => 'Guest Student',
            'guest_email' => 'guest@example.com',
            'guest_phone' => null,
            'manage_token' => hash('sha256', $plainToken),
            'starts_at' => now('UTC')->addDays($daysAhead)->setTime($hour, 0),
            'ends_at' => now('UTC')->addDays($daysAhead)->setTime($hour + 1, 0),
        ]);

        return [$booking, $plainToken];
    }

    /** Reads the payment reference generated by initiate() — never set at booking creation time. */
    private function paymentReference(Booking $booking): string
    {
        return (string) $booking->refresh()->payment_reference;
    }

    private function checkoutSignature(string $orderId, string $paymentId): string
    {
        return hash_hmac('sha256', "{$orderId}|{$paymentId}", self::KEY_SECRET);
    }

    private function webhookSignature(string $body): string
    {
        return hash_hmac('sha256', $body, self::WEBHOOK_SECRET);
    }

    private function postWebhook(array $payload, ?string $signature = null): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', '/api/webhooks/bookings/payments/razorpay', [], [], [], [
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature ?? $this->webhookSignature($body),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    private function capturedWebhookPayload(string $orderId, string $paymentId, string $reference, string $event = 'payment.captured'): array
    {
        return [
            'event' => $event,
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => $paymentId,
                        'order_id' => $orderId,
                        'amount' => 49900,
                        'currency' => 'INR',
                        'method' => 'upi',
                        'notes' => ['booking_reference' => $reference],
                    ],
                ],
            ],
        ];
    }

    // ── A. Provider / config ────────────────────────────────────────

    public function test_razorpay_provider_is_registered_and_selected_via_booking_settings(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi();

        $booking = $this->reserveStudent();
        $intent = app(BookingPaymentServiceInterface::class)->initiate($booking);

        $this->assertNotNull(BookingPayment::query()->where('booking_id', $booking->id)->first());
        $this->assertSame($booking->id, $intent->bookingId);
    }

    public function test_razorpay_blocks_non_inr_currency(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi();

        $booking = $this->reserveStudent();
        $booking->forceFill(['currency' => 'USD'])->save();

        $this->expectExceptionMessageMatches('/only supports INR/');
        app(BookingPaymentServiceInterface::class)->initiate($booking->refresh());
    }

    public function test_razorpay_requires_gateway_enabled_before_order_creation(): void
    {
        $this->configureRazorpay(enabled: false);
        $this->fakeRazorpayOrderApi();

        $booking = $this->reserveStudent();

        $this->expectExceptionMessageMatches('/not enabled/');
        app(BookingPaymentServiceInterface::class)->initiate($booking);
    }

    public function test_razorpay_amounts_are_integer_minor_units(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi();

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $payment = BookingPayment::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertIsInt($payment->amount_minor);
        $this->assertSame(49900, $payment->amount_minor);
    }

    public function test_razorpay_key_secret_is_never_exposed_via_checkout_payload(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi();

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $payload = app(RazorpayPaymentProvider::class)->checkoutPayload($booking);

        $this->assertArrayHasKey('key_id', $payload);
        $this->assertArrayNotHasKey('key_secret', $payload);
        $this->assertArrayNotHasKey('webhook_secret', $payload);
        $this->assertSame('rzp_test_key_id', $payload['key_id']);
    }

    // ── C. Order creation ───────────────────────────────────────────

    public function test_order_creation_creates_pending_booking_payment_row(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_ABC');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $this->assertDatabaseHas('booking_payments', [
            'booking_id' => $booking->id,
            'provider' => 'razorpay',
            'provider_order_id' => 'order_ABC',
            'status' => 'pending',
        ]);
    }

    public function test_order_creation_is_idempotent_on_repeated_initiate(): void
    {
        $this->configureRazorpay();

        $this->razorpayGateway = Mockery::mock(RazorpayGatewayClient::class);
        $this->razorpayGateway->shouldReceive('createOrder')
            ->once()
            ->andReturn(['id' => 'order_ABC', 'amount' => 49900, 'currency' => 'INR']);
        $this->app->instance(RazorpayGatewayClient::class, $this->razorpayGateway);

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        app(BookingPaymentServiceInterface::class)->initiate($booking->refresh());

        $this->assertSame(1, BookingPayment::query()->where('booking_id', $booking->id)->count());
    }

    public function test_order_creation_recovers_from_a_concurrent_duplicate_idempotency_key(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_RACE');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);

        // Simulate a concurrent request that has already inserted its row
        // (same idempotency_key) but has not yet received the order_id
        // back from Razorpay — the reusable-lookup requires a non-null
        // provider_order_id, so it won't match this in-flight row, and a
        // second insert with the same idempotency_key hits the DB's
        // unique constraint. That must be recovered, not surfaced raw.
        BookingPayment::query()->where('booking_id', $booking->id)->delete();
        BookingPayment::factory()->create([
            'booking_id' => $booking->id,
            'idempotency_key' => $reference,
            'status' => BookingPaymentRecordStatus::Pending,
            'provider_order_id' => null,
        ]);

        $this->expectExceptionMessageMatches('/already in progress/');
        app(BookingPaymentServiceInterface::class)->initiate($booking->refresh());
    }

    public function test_order_creation_does_not_mark_booking_paid_or_create_meeting(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi();

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status);
        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertNull($booking->meeting_url);
        $this->assertNull($booking->meeting_ref);
    }

    public function test_order_creation_failure_marks_payment_row_failed(): void
    {
        $this->configureRazorpay();

        $mock = Mockery::mock(RazorpayGatewayClient::class);
        $mock->shouldReceive('createOrder')->andThrow(new GatewayRequestException('bad request'));
        $this->app->instance(RazorpayGatewayClient::class, $mock);

        $booking = $this->reserveStudent();

        try {
            app(BookingPaymentServiceInterface::class)->initiate($booking);
            $this->fail('Expected a BookingException.');
        } catch (BookingException) {
            // expected
        }

        $this->assertDatabaseHas('booking_payments', ['booking_id' => $booking->id, 'status' => 'failed']);
    }

    // ── D. Verification / webhook ───────────────────────────────────

    public function test_checkout_signature_verification_succeeds_and_settles_booking(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_XYZ');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);

        $signature = $this->checkoutSignature('order_XYZ', 'pay_XYZ');
        app(RazorpayPaymentProvider::class)->verifyCheckout($booking, 'order_XYZ', 'pay_XYZ', $signature);
        $booking->refresh();

        if ($booking->payment_status->isPayable()) {
            app(BookingPaymentServiceInterface::class)->markPaid($booking, $reference);
        }

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertDatabaseHas('booking_payments', ['provider_order_id' => 'order_XYZ', 'status' => 'captured', 'provider_payment_id' => 'pay_XYZ']);
    }

    public function test_checkout_signature_verification_rejects_forged_signature(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_XYZ');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $this->expectException(InvalidPaymentWebhookException::class);
        app(RazorpayPaymentProvider::class)->verifyCheckout($booking, 'order_XYZ', 'pay_XYZ', 'forged-signature');
    }

    public function test_checkout_verification_rejects_order_from_a_different_booking(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_ONE');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $otherBooking = $this->reserveStudent(daysAhead: 5, hour: 14);

        $signature = $this->checkoutSignature('order_ONE', 'pay_ONE');

        $this->expectException(BookingException::class);
        app(RazorpayPaymentProvider::class)->verifyCheckout($otherBooking, 'order_ONE', 'pay_ONE', $signature);
    }

    public function test_webhook_payment_captured_settles_payment_and_confirms_booking(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_WH1');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);

        $this->postWebhook($this->capturedWebhookPayload('order_WH1', 'pay_WH1', $reference))
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
    }

    public function test_webhook_signature_invalid_is_rejected_without_processing(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_WH2');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);

        $this->postWebhook($this->capturedWebhookPayload('order_WH2', 'pay_WH2', $reference), signature: 'forged')
            ->assertStatus(401);

        $this->assertSame(BookingPaymentStatus::Pending, $booking->refresh()->payment_status);
    }

    public function test_webhook_signature_is_still_verified_when_gateway_is_disabled(): void
    {
        $this->configureRazorpay(enabled: false);

        $body = (string) json_encode(['event' => 'payment.captured']);

        $this->call('POST', '/api/webhooks/bookings/payments/razorpay', [], [], [], [
            'HTTP_X_RAZORPAY_SIGNATURE' => 'forged',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body)->assertStatus(401);
    }

    public function test_webhook_amount_mismatch_fails_safely(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_MISMATCH');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);

        $payload = $this->capturedWebhookPayload('order_MISMATCH', 'pay_MISMATCH', $reference);
        $payload['payload']['payment']['entity']['amount'] = 1;

        $this->postWebhook($payload)->assertStatus(401);

        $this->assertSame(BookingPaymentStatus::Pending, $booking->refresh()->payment_status);
    }

    public function test_webhook_currency_mismatch_fails_safely(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_MISMATCH2');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);

        $payload = $this->capturedWebhookPayload('order_MISMATCH2', 'pay_MISMATCH2', $reference);
        $payload['payload']['payment']['entity']['currency'] = 'USD';

        $this->postWebhook($payload)->assertStatus(401);

        $this->assertSame(BookingPaymentStatus::Pending, $booking->refresh()->payment_status);
    }

    public function test_webhook_payment_failed_keeps_reservation_for_retry(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_WH3');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);

        $payload = $this->capturedWebhookPayload('order_WH3', 'pay_WH3', $reference, 'payment.failed');
        $this->postWebhook($payload)->assertOk()->assertJsonPath('status', 'processed');

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Failed, $booking->payment_status);
        $this->assertNotNull($booking->reserved_until);
    }

    public function test_webhook_refund_cancels_booking(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_WH4');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);
        $this->postWebhook($this->capturedWebhookPayload('order_WH4', 'pay_WH4', $reference))->assertOk();

        $refundPayload = [
            'event' => 'refund.created',
            'payload' => ['refund' => ['entity' => ['id' => 'rfnd_1', 'order_id' => 'order_WH4', 'notes' => ['booking_reference' => $reference]]]],
        ];
        $this->postWebhook($refundPayload)->assertOk()->assertJsonPath('status', 'processed');

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Refunded, $booking->payment_status);
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
    }

    public function test_active_refund_calls_razorpay_and_cancels_booking(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_REFUND');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $signature = $this->checkoutSignature('order_REFUND', 'pay_REFUND');
        app(RazorpayPaymentProvider::class)->verifyCheckout($booking, 'order_REFUND', 'pay_REFUND', $signature);
        app(BookingPaymentServiceInterface::class)->markPaid($booking->refresh(), $this->paymentReference($booking));

        $this->razorpayGateway->shouldReceive('refundPayment')
            ->once()
            ->withArgs(fn (string $keyId, string $keySecret, string $paymentId, array $params): bool => $paymentId === 'pay_REFUND'
                && $params['amount'] === 49900)
            ->andReturn(['id' => 'rfnd_active']);

        $financeAdmin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        app(BookingPaymentServiceInterface::class)->refundViaProvider($booking->refresh(), $financeAdmin, 'change of plans');

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Refunded, $booking->payment_status);
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertDatabaseHas('booking_payments', ['provider_payment_id' => 'pay_REFUND', 'status' => 'refunded']);
    }

    public function test_active_refund_fails_safely_when_razorpay_rejects_it(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_REFUND2');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $signature = $this->checkoutSignature('order_REFUND2', 'pay_REFUND2');
        app(RazorpayPaymentProvider::class)->verifyCheckout($booking, 'order_REFUND2', 'pay_REFUND2', $signature);
        app(BookingPaymentServiceInterface::class)->markPaid($booking->refresh(), $this->paymentReference($booking));

        $this->razorpayGateway->shouldReceive('refundPayment')
            ->andThrow(new GatewayRequestException('already refunded'));

        $financeAdmin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->expectExceptionMessageMatches('/Razorpay refund failed/');
        app(BookingPaymentServiceInterface::class)->refundViaProvider($booking->refresh(), $financeAdmin, 'change of plans');
    }

    public function test_webhook_unrecognized_event_is_acknowledged_not_processed(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_WH5');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);

        $payload = $this->capturedWebhookPayload('order_WH5', 'pay_WH5', $reference, 'payment.authorized');
        $this->postWebhook($payload)->assertOk()->assertJsonPath('status', 'ignored');

        $this->assertSame(BookingPaymentStatus::Pending, $booking->refresh()->payment_status);
    }

    public function test_webhook_duplicate_delivery_is_idempotent(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_WH6');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);

        $payload = $this->capturedWebhookPayload('order_WH6', 'pay_WH6', $reference);
        $this->postWebhook($payload)->assertJsonPath('status', 'processed');
        $this->postWebhook($payload)->assertJsonPath('status', 'ignored');

        $this->assertSame(BookingPaymentStatus::Paid, $booking->refresh()->payment_status);
    }

    // ── F. Guest checkout is disabled ───────────────────────────────
    //
    // Phase 10.2C-Fix: "No unauthenticated user may initiate payment" /
    // "No guest payment UI" — GuestBookingPaymentController is no longer
    // routed (see routes/api.php), for any guest booking, legacy or not.

    public function test_guest_payment_initiate_route_no_longer_exists(): void
    {
        $this->configureRazorpay();
        [$booking, $token] = $this->reserveGuest();

        $this->postJson("/api/v1/guest/bookings/{$booking->reference}/payments/razorpay/initiate", ['token' => $token])
            ->assertStatus(404);

        $this->assertSame(BookingPaymentStatus::Pending, $booking->refresh()->payment_status);
    }

    public function test_guest_payment_verify_route_no_longer_exists(): void
    {
        $this->configureRazorpay();
        [$booking, $token] = $this->reserveGuest();

        $this->postJson("/api/v1/guest/bookings/{$booking->reference}/payments/razorpay/verify", [
            'token' => $token,
            'razorpay_order_id' => 'order_GONE',
            'razorpay_payment_id' => 'pay_GONE',
            'razorpay_signature' => 'irrelevant',
        ])->assertStatus(404);

        $this->assertSame(BookingPaymentStatus::Pending, $booking->refresh()->payment_status);
    }

    // ── Wallet & meeting boundary ────────────────────────────────────

    public function test_successful_razorpay_payment_never_creates_wallet_or_ledger_rows(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_WALLET');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);
        $this->postWebhook($this->capturedWebhookPayload('order_WALLET', 'pay_WALLET', $reference))->assertOk();

        $this->assertSame(0, Wallet::count());
        $this->assertSame(0, WalletLedgerEntry::count());
    }

    public function test_successful_razorpay_payment_does_not_create_a_meeting(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_MEET');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);
        $this->postWebhook($this->capturedWebhookPayload('order_MEET', 'pay_MEET', $reference))->assertOk();

        $booking->refresh();
        $this->assertNull($booking->meeting_url);
        $this->assertNull($booking->meeting_provider);
        $this->assertNull($booking->meeting_ref);
    }

    // ── Admin / Filament ─────────────────────────────────────────────

    public function test_manager_without_permission_cannot_view_booking_payments(): void
    {
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));

        $this->assertFalse($manager->can('viewAny', BookingPayment::class));
    }

    public function test_manager_with_permission_can_view_booking_payments(): void
    {
        Permission::firstOrCreate(['name' => 'ViewAny:BookingPayment', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'View:BookingPayment', 'guard_name' => 'web']);

        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $role = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $role->givePermissionTo(['ViewAny:BookingPayment', 'View:BookingPayment']);
        $manager->assignRole($role);

        $this->assertTrue($manager->can('viewAny', BookingPayment::class));
    }

    public function test_booking_payment_resource_has_no_create_edit_or_delete(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi();
        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $payment = BookingPayment::query()->where('booking_id', $booking->id)->firstOrFail();

        $this->assertFalse(BookingPaymentResource::canCreate());
        $this->assertFalse(BookingPaymentResource::canEdit($payment));
        $this->assertFalse(BookingPaymentResource::canDelete($payment));
        $this->assertFalse(BookingPaymentResource::canDeleteAny());
    }

    // ── Regression ───────────────────────────────────────────────────

    public function test_fake_provider_flow_is_unaffected_by_razorpay_registration(): void
    {
        // BookingSettings::payment_provider stays at its 'fake' default here.
        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = $this->paymentReference($booking);

        $body = (string) json_encode(['event' => 'succeeded', 'reference' => $reference]);
        $this->call('POST', '/api/webhooks/bookings/payments/fake', [], [], [], [
            'HTTP_X_BOOKING_PAYMENT_SIGNATURE' => hash_hmac('sha256', $body, (string) config('app.key')),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body)->assertOk()->assertJsonPath('status', 'processed');

        $this->assertSame(BookingPaymentStatus::Paid, $booking->refresh()->payment_status);
        // Phase 16A.1: the fake provider now creates (and captures) its own
        // BookingPayment row too, matching every real adapter — needed so
        // a fake-provider booking's cancellation refund has a captured
        // row to resolve, same as Razorpay/Stripe.
        $this->assertSame(1, BookingPayment::count());
        $this->assertSame('captured', BookingPayment::sole()->status->value);
    }
}
