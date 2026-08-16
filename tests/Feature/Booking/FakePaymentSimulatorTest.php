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
use App\Booking\Support\FakePaymentSimulator;
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
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * The local "Simulate success/failure" buttons.
 *
 * These previously called BookingPaymentService::markPaid() directly,
 * confirming the booking while leaving the obligation and attempt
 * Pending — so the invoice and notification listeners, which resolve a
 * CAPTURED obligation, silently did nothing. That produced a shape real
 * settlement can never produce and made local verification misleading.
 *
 * The guarantees below are what stop that returning, plus the one that
 * matters most: a dev button must never be able to fabricate a capture
 * against a REAL gateway attempt.
 */
class FakePaymentSimulatorTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private User $student;

    private User $teacher;

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

        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR');
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

    private function attempt(Booking $booking): Payment
    {
        return Payment::query()
            ->where('payable_id', $this->obligation($booking)->getKey())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->firstOrFail();
    }

    /**
     * The regression this class exists for: a simulated success must
     * produce the SAME durable state a signed webhook produces, receipt
     * and notifications included — not a confirmed booking with nothing
     * behind it.
     */
    public function test_simulated_success_settles_through_the_real_path_and_produces_a_receipt(): void
    {
        Notification::fake();
        $booking = $this->reserve();

        $this->assertTrue(app(FakePaymentSimulator::class)->simulate($booking, true));

        $booking->refresh();

        $this->assertSame(PaymentStatus::Paid, $this->attempt($booking)->status);
        $this->assertNotNull($this->attempt($booking)->paid_at);
        $this->assertNotNull($this->attempt($booking)->provider_payment_id);
        $this->assertSame(BookingPaymentRecordStatus::Captured, $this->obligation($booking)->status);
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);

        // The parts that silently did nothing before.
        $this->assertSame(1, Invoice::query()->count());
        Notification::assertSentTo($this->student, BookingPaymentSucceededNotification::class);
        Notification::assertSentTo($this->teacher, BookingPaidLessonConfirmedNotification::class);
    }

    public function test_simulated_failure_fails_only_the_attempt_and_preserves_retry(): void
    {
        Notification::fake();
        $booking = $this->reserve();

        app(FakePaymentSimulator::class)->simulate($booking, false);

        $booking->refresh();

        $this->assertSame(PaymentStatus::Failed, $this->attempt($booking)->status);
        $this->assertSame(BookingPaymentRecordStatus::Pending, $this->obligation($booking)->status);
        $this->assertTrue($booking->payment_status->isPayable());
        $this->assertNotSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(0, Invoice::query()->count());
        Notification::assertNotSentTo($this->student, BookingPaymentSucceededNotification::class);
    }

    public function test_a_replayed_simulated_success_settles_exactly_once(): void
    {
        Notification::fake();
        $booking = $this->reserve();

        app(FakePaymentSimulator::class)->simulate($booking, true);
        app(FakePaymentSimulator::class)->simulate($booking, true);
        app(FakePaymentSimulator::class)->simulate($booking, true);

        $this->assertSame(1, Invoice::query()->count());
        Notification::assertSentToTimes($this->student, BookingPaymentSucceededNotification::class, 1);
        Notification::assertSentToTimes($this->teacher, BookingPaidLessonConfirmedNotification::class, 1);
    }

    /**
     * MANDATORY: a developer convenience must never be able to assert
     * that a real gateway captured money. Doing so would write a fully
     * settled booking, a receipt and a notification for a payment that
     * never happened.
     */
    public function test_the_simulator_refuses_to_settle_a_real_provider_attempt(): void
    {
        Notification::fake();
        $booking = $this->reserve();

        // Re-badge the open attempt as a genuine Razorpay one.
        $attempt = $this->attempt($booking);
        $attempt->forceFill(['provider' => 'razorpay'])->save();

        $this->assertFalse(app(FakePaymentSimulator::class)->simulate($booking, true));

        $booking->refresh();

        $this->assertSame(PaymentStatus::Pending, $this->attempt($booking)->status);
        $this->assertNull($this->attempt($booking)->paid_at);
        $this->assertSame(BookingPaymentRecordStatus::Pending, $this->obligation($booking)->status);
        $this->assertNotSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(0, Invoice::query()->count());
        Notification::assertNotSentTo($this->student, BookingPaymentSucceededNotification::class);
    }

    public function test_the_simulator_is_unavailable_outside_local_and_testing(): void
    {
        $booking = $this->reserve();

        app()->detectEnvironment(fn (): string => 'production');

        $simulator = app(FakePaymentSimulator::class);
        $this->assertFalse($simulator->isAvailable());
        $this->assertFalse($simulator->simulate($booking, true));

        $this->assertSame(PaymentStatus::Pending, $this->attempt($booking->refresh())->status);
        $this->assertSame(0, Invoice::query()->count());
    }
}
