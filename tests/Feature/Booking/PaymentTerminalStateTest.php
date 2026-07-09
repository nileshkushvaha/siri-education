<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
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
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * Phase 10.2B — Option B: a payment that settles successfully after its
 * booking has already gone terminal (cancelled/expired reservation) is
 * never silently confirmed and never silently discarded. The charge is
 * preserved and redirected to the student's wallet; guest bookings (no
 * user account to hold a wallet) are flagged for manual resolution.
 * Replaces Phase 10.2's outright rejection of this scenario.
 */
class PaymentTerminalStateTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private const KEY_SECRET = 'test_key_secret';

    private const WEBHOOK_SECRET = 'test_webhook_secret';

    private User $student;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::query()->firstOrCreate(['code' => 'INR'], ['name' => 'Indian Rupee', 'symbol' => '₹', 'numeric_code' => '356', 'minor_units' => 2, 'status' => 'active']);

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

    private function configureRazorpay(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString(self::KEY_SECRET);
        $gateways->razorpay_webhook_secret = Crypt::encryptString(self::WEBHOOK_SECRET);
        $gateways->save();

        app(BookingSettings::class)->payment_provider = 'razorpay';
        app(BookingSettings::class)->save();
    }

    /** Binds a fake RazorpayGatewayClient instead of stubbing HTTP/cURL — see RazorpaySdkClient. */
    private function fakeRazorpayOrder(string $orderId): void
    {
        $mock = Mockery::mock(RazorpayGatewayClient::class);
        $mock->shouldReceive('createOrder')->andReturn(['id' => $orderId, 'amount' => 49900, 'currency' => 'INR']);
        $this->app->instance(RazorpayGatewayClient::class, $mock);
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
     * A legacy guest booking, as if created before Phase 10.2C-Fix's "no
     * guest booking" rule shipped — built directly, since
     * GuestBookingServiceInterface::book() can no longer produce one.
     * Late-payment/webhook handling must still resolve these correctly
     * (manual_resolution_required, not wallet credit — no user account
     * exists to hold a wallet).
     *
     * @return array{0: Booking, 1: string} booking + plain manage token
     */
    private function reserveGuest(): array
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
            'starts_at' => now('UTC')->addDays(4)->setTime(11, 0),
            'ends_at' => now('UTC')->addDays(4)->setTime(12, 0),
        ]);

        return [$booking, $plainToken];
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

    private function capturedWebhookPayload(string $orderId, string $paymentId, string $reference): array
    {
        return [
            'event' => 'payment.captured',
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

    /** Reserves + initiates a Razorpay payment for the student, then cancels the booking while payment_status is still Pending. */
    private function reserveInitiateAndCancel(): array
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrder('order_TERM1');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = (string) $booking->refresh()->payment_reference;

        $booking = app(BookingServiceInterface::class)->cancel($booking, new CancelBookingData(
            BookingActor::System,
            'Payment was not completed within the reservation window.',
        ));

        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status);

        return [$booking, $reference];
    }

    // ── Student: direct service call ──────────────────────────────────

    public function test_cancelled_booking_late_payment_credits_student_wallet_once_and_does_not_confirm(): void
    {
        [$booking, $reference] = $this->reserveInitiateAndCancel();

        $result = app(BookingPaymentServiceInterface::class)->markPaid($booking, $reference);

        $this->assertSame(BookingStatus::Cancelled, $result->status);
        $this->assertSame(BookingPaymentStatus::Refunded, $result->payment_status);

        $wallet = Wallet::query()->where('user_id', $this->student->id)->firstOrFail();
        $this->assertSame(49900, $wallet->balance_minor);

        $entry = WalletLedgerEntry::query()->where('wallet_id', $wallet->id)->firstOrFail();
        $this->assertSame(WalletLedgerEntryType::LatePaymentCredit, $entry->entry_type);
        $this->assertSame(49900, $entry->amount_minor);
    }

    public function test_expired_reservation_late_payment_credits_student_wallet(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrder('order_TERM2');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = (string) $booking->refresh()->payment_reference;

        $booking->forceFill(['reserved_until' => now()->subMinute()])->save();
        $this->artisan('booking:release-expired')->assertSuccessful();
        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);

        app(BookingPaymentServiceInterface::class)->markPaid($booking, $reference);

        $wallet = Wallet::query()->where('user_id', $this->student->id)->firstOrFail();
        $this->assertSame(49900, $wallet->balance_minor);
    }

    public function test_normal_non_terminal_booking_can_still_be_marked_paid(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrder('order_NORMAL1');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = (string) $booking->refresh()->payment_reference;

        $result = app(BookingPaymentServiceInterface::class)->markPaid($booking, $reference);

        $this->assertSame(BookingPaymentStatus::Paid, $result->payment_status);
        $this->assertSame(BookingStatus::Confirmed, $result->status);
        $this->assertSame(0, Wallet::query()->count());
    }

    // ── Student: late webhook ──────────────────────────────────────────

    public function test_late_razorpay_webhook_for_cancelled_booking_credits_wallet_and_does_not_confirm(): void
    {
        [$booking, $reference] = $this->reserveInitiateAndCancel();

        $response = $this->postWebhook($this->capturedWebhookPayload('order_TERM1', 'pay_TERM1', $reference));

        $response->assertOk()->assertJson(['status' => 'processed']);

        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame(BookingPaymentStatus::Refunded, $booking->payment_status);
        $this->assertSame(1, WalletLedgerEntry::query()->count());
    }

    public function test_late_razorpay_webhook_for_expired_reservation_credits_wallet(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrder('order_TERM3');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = (string) $booking->refresh()->payment_reference;

        $booking->forceFill(['reserved_until' => now()->subMinute()])->save();
        $this->artisan('booking:release-expired')->assertSuccessful();
        $booking->refresh();

        $response = $this->postWebhook($this->capturedWebhookPayload('order_TERM3', 'pay_TERM3', $reference));

        $response->assertOk()->assertJson(['status' => 'processed']);
        $this->assertSame(1, WalletLedgerEntry::query()->count());
    }

    public function test_duplicate_late_webhook_does_not_duplicate_wallet_credit(): void
    {
        [, $reference] = $this->reserveInitiateAndCancel();

        $payload = $this->capturedWebhookPayload('order_TERM1', 'pay_TERM1', $reference);
        $this->postWebhook($payload)->assertOk()->assertJson(['status' => 'processed']);
        $this->postWebhook($payload)->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(1, WalletLedgerEntry::query()->count());

        $wallet = Wallet::query()->where('user_id', $this->student->id)->firstOrFail();
        $this->assertSame(49900, $wallet->balance_minor);
    }

    // ── No side effects ─────────────────────────────────────────────────

    public function test_no_meeting_created_for_late_terminal_payment(): void
    {
        [$booking, $reference] = $this->reserveInitiateAndCancel();

        $this->postWebhook($this->capturedWebhookPayload('order_TERM1', 'pay_TERM1', $reference));

        $booking->refresh();
        $this->assertNull($booking->meeting_provider);
        $this->assertNull($booking->meeting_ref);
        $this->assertNull($booking->meeting_url);
    }

    public function test_amount_mismatch_late_webhook_does_not_credit_wallet(): void
    {
        [$booking, $reference] = $this->reserveInitiateAndCancel();

        $payload = $this->capturedWebhookPayload('order_TERM1', 'pay_TERM1', $reference);
        $payload['payload']['payment']['entity']['amount'] = 999999;

        $this->postWebhook($payload)->assertStatus(401);

        $this->assertSame(0, WalletLedgerEntry::query()->count());
        $this->assertSame(BookingPaymentStatus::Pending, $booking->refresh()->payment_status);
    }

    // ── Guest: manual resolution ─────────────────────────────────────────

    public function test_guest_cancelled_booking_late_payment_does_not_confirm_and_does_not_create_wallet(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrder('order_GUEST_TERM1');

        [$booking] = $this->reserveGuest();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = (string) $booking->refresh()->payment_reference;

        $booking = app(BookingServiceInterface::class)->cancel($booking, new CancelBookingData(
            BookingActor::System,
            'Payment was not completed within the reservation window.',
        ));

        $response = $this->postWebhook($this->capturedWebhookPayload('order_GUEST_TERM1', 'pay_GUEST1', $reference));

        $response->assertOk()->assertJson(['status' => 'processed']);

        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        // Guest has no user account — payment_status is deliberately left
        // unchanged (Pending) rather than claiming a resolution that
        // didn't happen; the resolution lives on the BookingPayment row.
        $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status);
        $this->assertSame(0, Wallet::query()->count());
        $this->assertSame(0, WalletLedgerEntry::query()->count());

        $payment = BookingPayment::query()->where('idempotency_key', $reference)->firstOrFail();
        $this->assertTrue($payment->metadata['manual_resolution_required'] ?? false);
    }

    public function test_duplicate_guest_late_webhook_is_idempotent(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrder('order_GUEST_TERM2');

        [$booking] = $this->reserveGuest();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = (string) $booking->refresh()->payment_reference;

        app(BookingServiceInterface::class)->cancel($booking, new CancelBookingData(
            BookingActor::System,
            'Payment was not completed within the reservation window.',
        ));

        $payload = $this->capturedWebhookPayload('order_GUEST_TERM2', 'pay_GUEST2', $reference);
        $this->postWebhook($payload)->assertOk()->assertJson(['status' => 'processed']);
        $this->postWebhook($payload)->assertOk()->assertJson(['status' => 'processed']);

        $this->assertSame(0, Wallet::query()->count());

        $payment = BookingPayment::query()->where('idempotency_key', $reference)->firstOrFail();
        $this->assertTrue($payment->metadata['late_terminal_handled'] ?? false);
    }
}
