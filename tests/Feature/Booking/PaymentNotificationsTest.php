<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\Weekday;
use App\Booking\Payments\RazorpayPaymentProvider;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Notifications\Booking\BookingPaymentSucceededNotification;
use App\Notifications\Booking\BookingPendingPaymentNotification;
use App\Settings\BookingSettings;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

class PaymentNotificationsTest extends TestCase
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

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);

        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $this->teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR');
        $this->assignBillingCountry($this->student, $priced['country']);

        BookingType::query()->firstOrCreate(
            ['key' => 'free_demo'],
            ['name' => 'Free Demo', 'duration_minutes' => 30, 'requires_approval' => false, 'is_paid' => false, 'is_active' => true],
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function book(string $typeKey, int $hour = 10): Booking
    {
        return app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: $typeKey,
            studentId: $this->student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(3)->setTime($hour, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ))->refresh();
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

    private function fakeRazorpayOrderApi(string $orderId = 'order_TEST123'): void
    {
        $gateway = Mockery::mock(RazorpayGatewayClient::class);
        $gateway->shouldReceive('createOrder')->andReturn(['id' => $orderId, 'amount' => 49900, 'currency' => 'INR']);
        $this->app->instance(RazorpayGatewayClient::class, $gateway);
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

    // ── Pending payment ──────────────────────────────────────────────

    public function test_pending_paid_booking_sends_pending_payment_notification_to_student(): void
    {
        Notification::fake();

        $booking = $this->book('paid_one_to_one');

        Notification::assertSentTo(
            $this->student,
            BookingPendingPaymentNotification::class,
            fn ($notification): bool => $notification->booking->is($booking),
        );
        Notification::assertNotSentTo($this->teacher, BookingPendingPaymentNotification::class);
    }

    public function test_pending_payment_notification_not_sent_for_free_demo_booking(): void
    {
        Notification::fake();

        $this->book('free_demo');

        Notification::assertNotSentTo($this->student, BookingPendingPaymentNotification::class);
    }

    // ── Payment success ──────────────────────────────────────────────

    public function test_verified_payment_success_sends_payment_succeeded_notification_to_student_only(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_NOTIF1');
        $booking = $this->book('paid_one_to_one');
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = (string) $booking->refresh()->payment_reference;

        Notification::fake();
        $this->postWebhook($this->capturedWebhookPayload('order_NOTIF1', 'pay_NOTIF1', $reference))->assertOk();

        Notification::assertSentTo($this->student, BookingPaymentSucceededNotification::class);
        Notification::assertNotSentTo($this->teacher, BookingPaymentSucceededNotification::class);
    }

    public function test_frontend_checkout_success_alone_does_not_send_payment_succeeded_notification(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_FRONT1');
        $booking = $this->book('paid_one_to_one');
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        Notification::fake();
        $signature = hash_hmac('sha256', 'order_FRONT1|pay_FRONT1', self::KEY_SECRET);
        app(RazorpayPaymentProvider::class)->verifyCheckout($booking, 'order_FRONT1', 'pay_FRONT1', $signature);

        Notification::assertNotSentTo($this->student, BookingPaymentSucceededNotification::class);
    }

    public function test_duplicate_payment_webhook_does_not_duplicate_notifications(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_DUP1');
        $booking = $this->book('paid_one_to_one');
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = (string) $booking->refresh()->payment_reference;

        Notification::fake();
        $payload = $this->capturedWebhookPayload('order_DUP1', 'pay_DUP1', $reference);
        $this->postWebhook($payload)->assertOk()->assertJson(['status' => 'processed']);
        $this->postWebhook($payload)->assertOk()->assertJson(['status' => 'ignored']);

        Notification::assertSentToTimes($this->student, BookingPaymentSucceededNotification::class, 1);
    }

    // ── Content safety ───────────────────────────────────────────────

    public function test_payment_succeeded_content_has_amount_but_no_provider_ids(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_SAFE1');
        $booking = $this->book('paid_one_to_one');
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = (string) $booking->refresh()->payment_reference;

        Notification::fake();
        $this->postWebhook($this->capturedWebhookPayload('order_SAFE1', 'pay_SAFE1', $reference))->assertOk();

        Notification::assertSentTo($this->student, BookingPaymentSucceededNotification::class, function ($notification): bool {
            $mail = $notification->toMail($this->student);
            $content = json_encode([$mail->subject, $mail->introLines, $mail->outroLines]);

            $this->assertStringContainsString('INR 499', $content);
            $this->assertStringNotContainsString('order_SAFE1', $content);
            $this->assertStringNotContainsString('pay_SAFE1', $content);
            $this->assertStringNotContainsString('rzp_', $content);
            $this->assertStringNotContainsString('wallet', strtolower($content));

            return true;
        });
    }
}
