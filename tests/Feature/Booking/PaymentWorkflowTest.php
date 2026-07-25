<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Models\Booking;
use App\Models\BookingActivity;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

class PaymentWorkflowTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

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

        // Phase 10.2D-Cleanup-Fix: BookingType::factory()->paid() no
        // longer carries a price — createPaidBookingTypeWithPrice() also
        // seeds the matching StudentLessonPrice (USD, all levels, 60min).
        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 49.99, 'USD');
        $this->assignBillingCountry($this->student, $priced['country']);
    }

    /** @return array{Booking, string} booking + payment reference */
    private function reserve(int $daysAhead = 3, int $hour = 10): array
    {
        $booking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays($daysAhead)->setTime($hour, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ));

        $intent = app(BookingPaymentServiceInterface::class)->initiate($booking);

        return [$booking->refresh(), $intent->reference];
    }

    private function webhook(array $payload, ?string $signature = null): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', '/api/webhooks/bookings/payments/fake', [], [], [], [
            'HTTP_X_BOOKING_PAYMENT_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, (string) config('app.key')),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    public function test_paid_booking_reserves_the_slot_pending_payment(): void
    {
        [$booking] = $this->reserve();

        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertSame(BookingPaymentStatus::Pending, $booking->payment_status);
        $this->assertNotNull($booking->reserved_until);
        $this->assertSame('49.99', $booking->price);
    }

    public function test_success_webhook_settles_payment_and_confirms_booking(): void
    {
        [$booking, $reference] = $this->reserve();

        $this->webhook(['event' => 'succeeded', 'reference' => $reference])
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertNull($booking->reserved_until);
        $this->assertTrue(BookingActivity::query()
            ->where('booking_id', $booking->id)
            ->where('action', BookingActivityAction::PaymentStatusChanged)
            ->exists());
    }

    public function test_successful_payment_is_recorded_in_the_unified_activity_log(): void
    {
        [$booking, $reference] = $this->reserve();

        $this->webhook(['event' => 'succeeded', 'reference' => $reference])->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'payments',
            'event' => 'payment_paid',
            'subject_type' => Booking::class,
            'subject_id' => $booking->id,
        ]);
    }

    public function test_failed_payment_is_recorded_in_the_unified_activity_log(): void
    {
        [, $reference] = $this->reserve();

        $this->webhook(['event' => 'failed', 'reference' => $reference, 'reason' => 'card declined'])->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'payments',
            'event' => 'payment_failed',
        ]);
    }

    public function test_failure_webhook_keeps_reservation_and_allows_retry(): void
    {
        [$booking, $reference] = $this->reserve();

        $this->webhook(['event' => 'failed', 'reference' => $reference, 'reason' => 'card declined'])
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Failed, $booking->payment_status);
        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertNotNull($booking->reserved_until);

        // Retry: re-initiate returns to pending with the same reference, then succeed.
        $retry = app(BookingPaymentServiceInterface::class)->initiate($booking);
        $this->assertSame($reference, $retry->reference);

        $this->webhook(['event' => 'succeeded', 'reference' => $reference])->assertOk();
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
    }

    public function test_invalid_signature_is_rejected_without_processing(): void
    {
        [$booking, $reference] = $this->reserve();

        $this->webhook(['event' => 'succeeded', 'reference' => $reference], signature: 'forged')
            ->assertStatus(401);

        $this->assertSame(BookingPaymentStatus::Pending, $booking->refresh()->payment_status);
    }

    public function test_unknown_reference_and_replays_are_acknowledged_not_processed(): void
    {
        $this->webhook(['event' => 'succeeded', 'reference' => 'PAY-UNKNOWN'])
            ->assertOk()
            ->assertJsonPath('status', 'ignored');

        [$booking, $reference] = $this->reserve();
        $this->webhook(['event' => 'succeeded', 'reference' => $reference])->assertJsonPath('status', 'processed');
        // Replayed delivery: acknowledged so the provider stops retrying, no state change.
        $this->webhook(['event' => 'succeeded', 'reference' => $reference])->assertJsonPath('status', 'ignored');

        $this->assertSame(BookingPaymentStatus::Paid, $booking->refresh()->payment_status);
    }

    public function test_cancelling_a_paid_booking_refunds_it(): void
    {
        [$booking, $reference] = $this->reserve();
        $this->webhook(['event' => 'succeeded', 'reference' => $reference])->assertOk();

        app(BookingServiceInterface::class)->cancel(
            $booking->refresh(),
            new CancelBookingData(BookingActor::Student, 'change of plans'),
        );

        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame(BookingPaymentStatus::Refunded, $booking->payment_status);
    }

    public function test_refund_webhook_cancels_the_booking(): void
    {
        [$booking, $reference] = $this->reserve();
        $this->webhook(['event' => 'succeeded', 'reference' => $reference])->assertOk();

        $this->webhook(['event' => 'refunded', 'reference' => $reference, 'reason' => 'disputed'])
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $booking->refresh();
        $this->assertSame(BookingPaymentStatus::Refunded, $booking->payment_status);
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
    }

    public function test_expired_reservations_are_released(): void
    {
        [$booking] = $this->reserve();
        $booking->forceFill(['reserved_until' => now()->subMinute()])->save();

        $this->artisan('booking:release-expired')->assertSuccessful();

        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertNotNull($booking->cancelled_at);
    }
}
