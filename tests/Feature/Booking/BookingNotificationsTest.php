<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Notifications\Booking\BookingCancelledNotification;
use App\Notifications\Booking\BookingConfirmedNotification;
use App\Notifications\Booking\BookingExpiredNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

class BookingNotificationsTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

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

        BookingType::query()->firstOrCreate(
            ['key' => 'free_demo'],
            ['name' => 'Free Demo', 'duration_minutes' => 30, 'max_attendees' => 1, 'requires_approval' => false, 'is_paid' => false, 'is_active' => true],
        );
    }

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

    // ── Confirmed ────────────────────────────────────────────────────

    public function test_booking_confirmed_notifies_student_and_instructor(): void
    {
        Notification::fake();

        $booking = $this->book('free_demo'); // auto-confirms

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        Notification::assertSentTo($this->student, BookingConfirmedNotification::class);
        Notification::assertSentTo($this->teacher, BookingConfirmedNotification::class);
    }

    public function test_instructor_confirmed_copy_contains_no_student_paid_amount(): void
    {
        Notification::fake();

        $booking = $this->book('paid_one_to_one');
        app(BookingServiceInterface::class)->confirm($booking);

        Notification::assertSentTo($this->teacher, BookingConfirmedNotification::class, function ($notification): bool {
            $mail = $notification->toMail($this->teacher);
            $content = json_encode([$mail->subject, $mail->introLines, $mail->outroLines]);

            $this->assertStringNotContainsString('499', $content);
            $this->assertStringNotContainsString('INR', $content);
            $this->assertStringNotContainsString('price', strtolower($content));
            $this->assertStringNotContainsString('wallet', strtolower($content));

            return true;
        });
    }

    public function test_cancelled_booking_does_not_receive_confirmed_notification(): void
    {
        $booking = $this->book('paid_one_to_one');

        Notification::fake();
        app(BookingServiceInterface::class)->cancel($booking, new CancelBookingData(BookingActor::Attendee, 'Changed my mind'));

        Notification::assertNotSentTo($this->student, BookingConfirmedNotification::class);
        Notification::assertNotSentTo($this->teacher, BookingConfirmedNotification::class);
    }

    // ── Cancelled / expired ──────────────────────────────────────────

    public function test_booking_cancelled_notifies_student_and_instructor(): void
    {
        $booking = $this->book('free_demo');

        Notification::fake();
        app(BookingServiceInterface::class)->cancel($booking, new CancelBookingData(BookingActor::Attendee, 'Conflict'));

        Notification::assertSentTo($this->student, BookingCancelledNotification::class);
        Notification::assertSentTo($this->teacher, BookingCancelledNotification::class);
    }

    public function test_expired_reservation_sends_expired_notification_to_student(): void
    {
        $booking = $this->book('paid_one_to_one');
        $booking->forceFill(['reserved_until' => now()->subMinute()])->save();

        Notification::fake();
        $this->artisan('booking:release-expired')->assertSuccessful();

        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);
        Notification::assertSentTo($this->student, BookingExpiredNotification::class);
        // The expiry flavor replaces the generic cancellation for the student…
        Notification::assertNotSentTo($this->student, BookingCancelledNotification::class);
        // …while the host still gets the standard cancellation notice.
        Notification::assertSentTo($this->teacher, BookingCancelledNotification::class);
        Notification::assertNotSentTo($this->teacher, BookingExpiredNotification::class);
    }

    public function test_manual_cancellation_still_sends_cancelled_not_expired(): void
    {
        $booking = $this->book('paid_one_to_one');

        Notification::fake();
        app(BookingServiceInterface::class)->cancel($booking, new CancelBookingData(BookingActor::Attendee, 'Conflict'));

        Notification::assertSentTo($this->student, BookingCancelledNotification::class);
        Notification::assertNotSentTo($this->student, BookingExpiredNotification::class);
    }

    // ── Queue behavior ───────────────────────────────────────────────

    public function test_booking_notifications_are_queued_on_the_notifications_queue(): void
    {
        $booking = Booking::factory()->confirmed()->create();

        foreach ([
            new BookingConfirmedNotification($booking),
            new BookingExpiredNotification($booking),
        ] as $notification) {
            $this->assertInstanceOf(ShouldQueue::class, $notification);
            $this->assertSame('notifications', $notification->queue);
        }
    }
}
