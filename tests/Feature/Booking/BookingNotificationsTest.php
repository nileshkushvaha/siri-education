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
use App\Booking\Events\BookingConfirmed;
use App\Booking\Events\BookingRescheduled;
use App\Listeners\Booking\SendBookingNotifications;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\NotificationDispatchLog;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Notifications\Booking\BookingCancelledNotification;
use App\Notifications\Booking\BookingConfirmedNotification;
use App\Notifications\Booking\BookingExpiredNotification;
use App\Notifications\Booking\BookingRescheduledNotification;
use Carbon\CarbonImmutable;
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
            ['name' => 'Free Demo', 'duration_minutes' => 30, 'requires_approval' => false, 'is_paid' => false, 'is_active' => true],
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
        app(BookingServiceInterface::class)->cancel($booking, new CancelBookingData(BookingActor::Student, 'Changed my mind'));

        Notification::assertNotSentTo($this->student, BookingConfirmedNotification::class);
        Notification::assertNotSentTo($this->teacher, BookingConfirmedNotification::class);
    }

    // ── Cancelled / expired ──────────────────────────────────────────

    public function test_booking_cancelled_notifies_student_and_instructor(): void
    {
        $booking = $this->book('free_demo');

        Notification::fake();
        app(BookingServiceInterface::class)->cancel($booking, new CancelBookingData(BookingActor::Student, 'Conflict'));

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
        app(BookingServiceInterface::class)->cancel($booking, new CancelBookingData(BookingActor::Student, 'Conflict'));

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

    // ── Idempotency (Phase 17V closure) ─────────────────────────────

    public function test_redelivered_confirmed_event_does_not_duplicate_notifications(): void
    {
        Notification::fake();

        $booking = $this->book('free_demo')->fresh(); // auto-confirms
        $listener = app(SendBookingNotifications::class);
        $event = new BookingConfirmed($booking);

        // The real BookingConfirmed dispatch already fired once via
        // book(); redeliver the exact same event twice more directly.
        $listener->handleConfirmed($event);
        $listener->handleConfirmed($event);

        Notification::assertSentToTimes($this->student, BookingConfirmedNotification::class, 1);
        Notification::assertSentToTimes($this->teacher, BookingConfirmedNotification::class, 1);
        $this->assertSame(
            2, // one per recipient
            NotificationDispatchLog::query()->where('notification_class', BookingConfirmedNotification::class)->count(),
        );
    }

    public function test_two_distinct_reschedules_both_notify_but_a_replay_of_one_does_not(): void
    {
        Notification::fake();

        $booking = $this->book('paid_one_to_one')->fresh();
        $listener = app(SendBookingNotifications::class);

        $previousStartsAt = CarbonImmutable::parse($booking->starts_at);
        $previousEndsAt = CarbonImmutable::parse($booking->ends_at);

        $firstNewStart = now('UTC')->addDays(5)->setTime(11, 0)->toImmutable();
        $booking->forceFill(['starts_at' => $firstNewStart, 'ends_at' => $firstNewStart->addMinutes(60)])->save();
        $firstEvent = new BookingRescheduled($booking->fresh(), $previousStartsAt, $previousEndsAt);

        // Replay the same reschedule event twice.
        $listener->handleRescheduled($firstEvent);
        $listener->handleRescheduled($firstEvent);

        Notification::assertSentToTimes($this->student, BookingRescheduledNotification::class, 1);

        // A genuinely later, distinct reschedule must still notify.
        $secondNewStart = now('UTC')->addDays(6)->setTime(14, 0)->toImmutable();
        $booking->forceFill(['starts_at' => $secondNewStart, 'ends_at' => $secondNewStart->addMinutes(60)])->save();
        $secondEvent = new BookingRescheduled($booking->fresh(), $firstNewStart, $firstNewStart->addMinutes(60));

        $listener->handleRescheduled($secondEvent);

        Notification::assertSentToTimes($this->student, BookingRescheduledNotification::class, 2);
    }
}
