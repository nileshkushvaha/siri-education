<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Notifications\Booking\BookingPaidLessonConfirmedNotification;
use App\Notifications\Booking\BookingPaymentSucceededNotification;
use App\Payments\Enums\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * Task 3 §6/§7 — the non-success half of the payment lifecycle.
 *
 * `payment.failed` and `payment.authorized` are ordinary lifecycle
 * events, not refund functionality, and both must be safe:
 *
 *  - a failed ATTEMPT never fails the obligation, so a retry remains
 *    possible;
 *  - an authorized-but-uncaptured payment settles nothing;
 *  - neither ever produces a receipt or a success notification;
 *  - and, mandatorily, a late failure can never un-settle money that
 *    has already been collected. Provider events arrive out of order;
 *    settled financial state is monotonic.
 */
class PaymentFailureAndAuthorizationTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private User $student;

    private User $teacher;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['student', 'instructor', 'super_admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);

        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->teacher->assignRole('instructor');
        UserProfile::updateOrCreate(['user_id' => $this->teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $this->teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('super_admin');

        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 49.99, 'USD');
        $this->assignBillingCountry($this->student, $priced['country']);
    }

    private function reserve(): Booking
    {
        $booking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ));

        app(BookingPaymentServiceInterface::class)->initiate($booking);

        return $booking->refresh();
    }

    private function obligation(Booking $booking): BookingPayment
    {
        return BookingPayment::query()->where('booking_id', $booking->id)->sole();
    }

    private function latestAttempt(Booking $booking): Payment
    {
        // Two attempts created in the same second share a created_at, so
        // order by id as the tiebreaker — otherwise "the latest attempt"
        // is ambiguous and this helper silently returns the wrong row.
        return Payment::query()
            ->where('payable_id', $this->obligation($booking)->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->firstOrFail();
    }

    private function webhook(string $event, string $reference): TestResponse
    {
        $body = (string) json_encode(['event' => $event, 'reference' => $reference]);

        return $this->call('POST', '/api/webhooks/bookings/payments/fake', [], [], [], [
            'HTTP_X_BOOKING_PAYMENT_SIGNATURE' => hash_hmac('sha256', $body, (string) config('app.key')),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    private function assertNoSuccessCommunications(): void
    {
        Notification::assertNotSentTo($this->student, BookingPaymentSucceededNotification::class);
        Notification::assertNotSentTo($this->teacher, BookingPaidLessonConfirmedNotification::class);
        Notification::assertNotSentTo($this->admin, BookingPaymentSucceededNotification::class);
        Notification::assertNotSentTo($this->admin, BookingPaidLessonConfirmedNotification::class);
        $this->assertSame(0, Invoice::query()->count());
    }

    // ── payment.failed ────────────────────────────────────────────────

    public function test_a_failed_attempt_leaves_the_booking_unpaid_and_retryable(): void
    {
        Notification::fake();
        $booking = $this->reserve();

        $this->webhook('failed', (string) $this->latestAttempt($booking)->idempotency_key)->assertOk();

        $booking->refresh();

        $this->assertSame(PaymentStatus::Failed, $this->latestAttempt($booking)->status);
        $this->assertSame(BookingPaymentRecordStatus::Pending, $this->obligation($booking)->status);
        $this->assertTrue($booking->payment_status->isPayable());
        $this->assertNotSame(BookingStatus::Confirmed, $booking->status);

        $this->assertNoSuccessCommunications();
    }

    public function test_a_failed_attempt_permits_a_new_attempt_that_can_still_succeed(): void
    {
        $booking = $this->reserve();
        $first = $this->latestAttempt($booking);

        $this->webhook('failed', (string) $first->idempotency_key)->assertOk();

        // A new attempt is permitted precisely because the obligation
        // never became terminal.
        app(BookingPaymentServiceInterface::class)->initiate($booking->refresh());
        $second = $this->latestAttempt($booking);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(PaymentStatus::Pending, $second->status);

        $this->webhook('succeeded', (string) $second->idempotency_key)->assertOk();

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);

        // The earlier failure is history, not something rewritten.
        $this->assertSame(PaymentStatus::Failed, $first->refresh()->status);
    }

    public function test_a_replayed_failure_is_idempotent(): void
    {
        $booking = $this->reserve();
        $reference = (string) $this->latestAttempt($booking)->idempotency_key;

        $this->webhook('failed', $reference)->assertOk();
        $failedAt = $this->latestAttempt($booking)->failed_at;

        $this->webhook('failed', $reference)->assertOk()->assertJsonPath('status', 'ignored');
        $this->webhook('failed', $reference)->assertOk()->assertJsonPath('status', 'ignored');

        $this->assertSame(PaymentStatus::Failed, $this->latestAttempt($booking)->status);
        $this->assertEquals($failedAt, $this->latestAttempt($booking)->failed_at);
        $this->assertSame(1, Payment::query()->count());
    }

    /**
     * MANDATORY (§6): provider events arrive out of order. A failure for
     * an old attempt must never downgrade money already collected.
     */
    public function test_a_late_failure_cannot_unsettle_an_already_settled_booking(): void
    {
        $booking = $this->reserve();
        $reference = (string) $this->latestAttempt($booking)->idempotency_key;

        $this->webhook('succeeded', $reference)->assertOk()->assertJsonPath('status', 'processed');

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $receipts = Invoice::query()->count();

        // The straggler arrives after settlement.
        $this->webhook('failed', $reference)->assertOk()->assertJsonPath('status', 'ignored');

        $booking->refresh();
        $this->assertSame(PaymentStatus::Paid, $this->latestAttempt($booking)->status);
        $this->assertSame(BookingPaymentRecordStatus::Captured, $this->obligation($booking)->status);
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame($receipts, Invoice::query()->count());
    }

    public function test_a_failure_for_one_attempt_does_not_downgrade_a_different_settled_attempt(): void
    {
        $booking = $this->reserve();
        $first = $this->latestAttempt($booking);

        $this->webhook('failed', (string) $first->idempotency_key)->assertOk();

        app(BookingPaymentServiceInterface::class)->initiate($booking->refresh());
        $second = $this->latestAttempt($booking);
        $this->webhook('succeeded', (string) $second->idempotency_key)->assertOk();

        // Re-delivering the FIRST attempt's failure must not touch the
        // second attempt or the now-settled booking.
        $this->webhook('failed', (string) $first->idempotency_key)->assertOk();

        $booking->refresh();
        $this->assertSame(PaymentStatus::Failed, $first->refresh()->status);
        $this->assertSame(PaymentStatus::Paid, $second->refresh()->status);
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
    }

    // ── payment.authorized ────────────────────────────────────────────

    /**
     * §7 — Razorpay's `payment.authorized` means the money is held, not
     * taken. It maps to the generic Processing event, which settles
     * nothing; `payment.captured` remains the only success path.
     */
    public function test_an_authorized_but_uncaptured_payment_settles_nothing(): void
    {
        Notification::fake();
        $booking = $this->reserve();
        $attempt = $this->latestAttempt($booking);

        $body = (string) json_encode([
            'event' => 'payment.authorized',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_AUTH1',
                'order_id' => $attempt->provider_order_id,
                'amount' => (int) $attempt->amount_minor,
                'currency' => $attempt->currency_code,
                'notes' => ['payment_reference' => $attempt->idempotency_key],
            ]]],
        ]);

        $this->call('POST', '/api/webhooks/bookings/payments/fake', [], [], [], [
            'HTTP_X_BOOKING_PAYMENT_SIGNATURE' => hash_hmac('sha256', $body, (string) config('app.key')),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body)->assertOk()->assertJsonPath('status', 'ignored');

        $booking->refresh();

        $this->assertNotSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $this->assertNotSame(BookingStatus::Confirmed, $booking->status);
        $this->assertNotSame(PaymentStatus::Paid, $this->latestAttempt($booking)->status);
        $this->assertNoSuccessCommunications();
    }
}
