<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Events\BookingCancelled;
use App\Booking\Events\BookingConfirmed;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Enums\LessonAttendanceStatus;
use App\Lessons\Enums\LessonStatus;
use App\Lessons\Exceptions\InvalidLessonTransitionException;
use App\Lessons\Exceptions\LessonException;
use App\Models\AcademicLevel;
use App\Models\Booking;
use App\Models\Lesson;
use App\Models\SubjectTopic;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Settings\LessonSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lessons;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lessons = app(LessonLifecycleServiceInterface::class);
    }

    // ── Creation (Section E trigger) ─────────────────────────────────

    public function test_lesson_is_created_when_booking_confirmed_fires(): void
    {
        $booking = Booking::factory()->confirmed()->create();

        BookingConfirmed::dispatch($booking);

        $lesson = Lesson::query()->where('booking_id', $booking->id)->first();

        $this->assertNotNull($lesson);
        $this->assertSame(LessonStatus::Scheduled, $lesson->status);
        $this->assertSame($booking->attendee_id, $lesson->student_id);
        $this->assertSame($booking->host_id, $lesson->instructor_id);
        $this->assertTrue($booking->starts_at->equalTo($lesson->starts_at));
        $this->assertTrue($booking->ends_at->equalTo($lesson->ends_at));
        $this->assertSame(LessonAttendanceStatus::Pending, $lesson->student_attendance_status);
        $this->assertSame(LessonAttendanceStatus::Pending, $lesson->instructor_attendance_status);
    }

    public function test_lesson_snapshots_subject_and_topic_from_booking_meta(): void
    {
        $topic = SubjectTopic::factory()->create();

        $booking = Booking::factory()->confirmed()->create([
            'meta' => [
                'subject' => $topic->subject->slug,
                'grade' => 7,
                'topic' => $topic->slug,
                'topic_id' => $topic->id,
            ],
        ]);

        $lesson = $this->lessons->createFromBooking($booking);

        $this->assertSame($topic->subject_id, $lesson->subject_id);
        $this->assertSame($topic->id, $lesson->subject_topic_id);
        $this->assertSame(7, $lesson->metadata['grade']);
        $this->assertSame($topic->slug, $lesson->metadata['topic']);
        $this->assertSame($booking->reference, $lesson->metadata['booking_reference']);
    }

    public function test_lesson_creation_is_idempotent(): void
    {
        $booking = Booking::factory()->confirmed()->create();

        $first = $this->lessons->createFromBooking($booking);
        $second = $this->lessons->createFromBooking($booking);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Lesson::query()->where('booking_id', $booking->id)->count());
    }

    public function test_duplicate_booking_confirmed_event_does_not_duplicate_lesson(): void
    {
        $booking = Booking::factory()->confirmed()->create();

        BookingConfirmed::dispatch($booking);
        BookingConfirmed::dispatch($booking);

        $this->assertSame(1, Lesson::query()->where('booking_id', $booking->id)->count());
    }

    public function test_no_lesson_for_ineligible_bookings(): void
    {
        $pending = Booking::factory()->create();

        $guest = Booking::factory()->confirmed()->create([
            'attendee_id' => null,
            'guest_name' => 'Guest',
            'guest_email' => 'guest@example.com',
        ]);

        $unpaid = Booking::factory()->confirmed()->create([
            'payment_status' => BookingPaymentStatus::Pending,
        ]);

        $this->assertNull($this->lessons->createFromBooking($pending));
        $this->assertNull($this->lessons->createFromBooking($guest));
        $this->assertNull($this->lessons->createFromBooking($unpaid));
        $this->assertSame(0, Lesson::query()->count());
    }

    public function test_no_lesson_for_cancelled_expired_or_late_terminal_paid_bookings(): void
    {
        // Expired reservation: cancelled with the payment hold never settled.
        $expired = Booking::factory()->cancelled(BookingActor::System)->create([
            'payment_status' => BookingPaymentStatus::Pending,
        ]);

        // Option B late terminal payment: the payment reaches a terminal
        // Paid state after the booking was already cancelled — the booking
        // is no longer confirmed, so no lesson may appear.
        $lateTerminal = Booking::factory()->cancelled()->paid()->create();

        $refunded = Booking::factory()->confirmed()->create([
            'payment_status' => BookingPaymentStatus::Refunded,
        ]);

        $this->assertNull($this->lessons->createFromBooking($expired));
        $this->assertNull($this->lessons->createFromBooking($lateTerminal));
        $this->assertNull($this->lessons->createFromBooking($refunded));
        $this->assertSame(0, Lesson::query()->count());
    }

    public function test_lesson_snapshots_academic_level_when_grade_match_is_unambiguous(): void
    {
        $level = AcademicLevel::create([
            'name' => 'Middle School',
            'slug' => 'middle-school',
            'min_grade' => 6,
            'max_grade' => 8,
        ]);

        $unambiguous = $this->lessons->createFromBooking(
            Booking::factory()->confirmed()->create(['meta' => ['grade' => 7]]),
        );
        $this->assertSame($level->id, $unambiguous->academic_level_id);

        // A second overlapping level makes grade 7 ambiguous — FK stays
        // null and the raw grade in metadata remains the source of truth.
        AcademicLevel::create([
            'name' => 'Secondary',
            'slug' => 'secondary',
            'min_grade' => 7,
            'max_grade' => 10,
        ]);

        $ambiguous = $this->lessons->createFromBooking(
            Booking::factory()->confirmed()->create(['meta' => ['grade' => 7]]),
        );
        $this->assertNull($ambiguous->academic_level_id);
        $this->assertSame(7, $ambiguous->metadata['grade']);
    }

    public function test_paid_confirmed_booking_is_eligible(): void
    {
        $booking = Booking::factory()->confirmed()->paid()->create();

        $this->assertNotNull($this->lessons->createFromBooking($booking));
    }

    // ── Live + attendance ────────────────────────────────────────────

    public function test_mark_live_and_attendance(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);
        $instructor = $lesson->instructor;

        $lesson = $this->lessons->markLive($lesson, $instructor);
        $this->assertSame(LessonStatus::Live, $lesson->status);

        $lesson = $this->lessons->markStudentAttendance($lesson, LessonAttendanceStatus::Attended, $instructor);
        $this->assertSame(LessonAttendanceStatus::Attended, $lesson->student_attendance_status);
        $this->assertNotNull($lesson->student_attended_at);

        $lesson = $this->lessons->markInstructorAttendance($lesson, LessonAttendanceStatus::NoShow, $instructor);
        $this->assertSame(LessonAttendanceStatus::NoShow, $lesson->instructor_attendance_status);
        $this->assertNull($lesson->instructor_attended_at);
    }

    public function test_attendance_cannot_be_marked_on_finalized_lessons(): void
    {
        $lesson = $this->makeLesson();
        $this->lessons->complete($lesson, override: true);

        $this->expectException(LessonException::class);

        $this->lessons->markStudentAttendance($lesson->refresh(), LessonAttendanceStatus::Attended);
    }

    public function test_attendance_cannot_be_marked_back_to_pending(): void
    {
        $lesson = $this->makeLesson();

        $this->expectException(LessonException::class);

        $this->lessons->markStudentAttendance($lesson, LessonAttendanceStatus::Pending);
    }

    public function test_no_show_grace_period_blocks_early_marking_unless_overridden(): void
    {
        // Started 5 minutes ago — inside the default 15-minute grace window.
        $startsAt = now()->subMinutes(5);
        $booking = Booking::factory()->confirmed()->create([
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(60),
        ]);
        $lesson = $this->lessons->createFromBooking($booking);

        try {
            $this->lessons->markStudentAttendance($lesson, LessonAttendanceStatus::NoShow);
            $this->fail('Expected the no-show grace period to reject the marking.');
        } catch (LessonException) {
            // expected
        }

        $lesson = $this->lessons->markStudentAttendance($lesson, LessonAttendanceStatus::NoShow, override: true);
        $this->assertSame(LessonAttendanceStatus::NoShow, $lesson->student_attendance_status);
    }

    // ── Completion ───────────────────────────────────────────────────

    public function test_complete_sets_fields_and_syncs_booking(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);
        $instructor = $lesson->instructor;

        $lesson = $this->lessons->complete($lesson, $instructor, 'Covered chapters 1-3.');

        $this->assertSame(LessonStatus::Completed, $lesson->status);
        $this->assertNotNull($lesson->completed_at);
        $this->assertNull($lesson->auto_completed_at);
        $this->assertSame($instructor->id, $lesson->completed_by);
        $this->assertSame('Covered chapters 1-3.', $lesson->completion_notes);
        // Confirming completion counts unflagged parties as attended.
        $this->assertSame(LessonAttendanceStatus::Attended, $lesson->student_attendance_status);
        $this->assertSame(LessonAttendanceStatus::Attended, $lesson->instructor_attendance_status);

        $this->assertSame(BookingStatus::Completed, $lesson->booking->fresh()->status);
    }

    public function test_completion_before_lesson_end_requires_override(): void
    {
        $lesson = $this->makeLesson();

        try {
            $this->lessons->complete($lesson);
            $this->fail('Expected early completion without override to be rejected.');
        } catch (LessonException) {
            // expected
        }

        $lesson = $this->lessons->complete($lesson, override: true);
        $this->assertSame(LessonStatus::Completed, $lesson->status);
    }

    public function test_completion_requires_confirmed_attendance_when_setting_enabled(): void
    {
        $settings = app(LessonSettings::class);
        $settings->require_instructor_completion = true;
        $settings->save();

        $lesson = $this->makeLesson(endedHoursAgo: 1);

        try {
            $this->lessons->complete($lesson);
            $this->fail('Expected completion without instructor attendance to be rejected.');
        } catch (LessonException) {
            // expected
        }

        $this->lessons->markInstructorAttendance($lesson, LessonAttendanceStatus::Attended);
        $lesson = $this->lessons->complete($lesson);

        $this->assertSame(LessonStatus::Completed, $lesson->status);
    }

    public function test_duplicate_completion_is_idempotent(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);

        $lesson = $this->lessons->complete($lesson);
        $completedAt = $lesson->completed_at;

        $again = $this->lessons->complete($lesson);

        $this->assertSame(LessonStatus::Completed, $again->status);
        $this->assertTrue($completedAt->equalTo($again->completed_at));
        $this->assertSame(1, Lesson::query()->where('booking_id', $lesson->booking_id)->count());
    }

    public function test_completion_touches_no_wallet_and_creates_no_payout(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);

        $this->lessons->complete($lesson);

        $this->assertSame(0, Wallet::query()->count());
        $this->assertSame(0, WalletLedgerEntry::query()->count());
    }

    public function test_invalid_transition_throws(): void
    {
        $lesson = $this->makeLesson();
        $this->lessons->cancel($lesson);

        $this->expectException(InvalidLessonTransitionException::class);

        $this->lessons->complete($lesson->refresh(), override: true);
    }

    // ── No-show ──────────────────────────────────────────────────────

    public function test_finalize_no_show_derives_outcome_from_attendance(): void
    {
        $cases = [
            [LessonAttendanceStatus::NoShow, LessonAttendanceStatus::Attended, LessonStatus::StudentNoShow],
            [LessonAttendanceStatus::Attended, LessonAttendanceStatus::NoShow, LessonStatus::InstructorNoShow],
            [LessonAttendanceStatus::NoShow, LessonAttendanceStatus::NoShow, LessonStatus::BothNoShow],
        ];

        foreach ($cases as [$student, $instructor, $expected]) {
            $lesson = $this->makeLesson(endedHoursAgo: 1);
            $this->lessons->markStudentAttendance($lesson, $student);
            $this->lessons->markInstructorAttendance($lesson, $instructor);

            $lesson = $this->lessons->finalizeNoShow($lesson);

            $this->assertSame($expected, $lesson->status);
            $this->assertSame(BookingStatus::NoShow, $lesson->booking->fresh()->status);
        }
    }

    public function test_finalize_no_show_requires_a_recorded_no_show(): void
    {
        $lesson = $this->makeLesson();

        $this->expectException(LessonException::class);

        $this->lessons->finalizeNoShow($lesson);
    }

    // ── Dispute ──────────────────────────────────────────────────────

    public function test_dispute_and_resolution(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 1);
        $student = $lesson->student;

        $lesson = $this->lessons->complete($lesson);
        $lesson = $this->lessons->dispute($lesson, $student, 'The lesson ended 20 minutes early.');

        $this->assertSame(LessonStatus::Disputed, $lesson->status);
        $this->assertSame('The lesson ended 20 minutes early.', $lesson->dispute_reason);

        // Admin resolves the dispute back to completed.
        $lesson = $this->lessons->complete($lesson, override: true);
        $this->assertSame(LessonStatus::Completed, $lesson->status);
    }

    // ── Booking → lesson sync ────────────────────────────────────────

    public function test_booking_cancellation_cancels_the_lesson(): void
    {
        $lesson = $this->makeLesson();
        $booking = $lesson->booking;

        BookingCancelled::dispatch($booking, new CancelBookingData(BookingActor::Admin, 'Emergency.'));

        $lesson->refresh();
        $this->assertSame(LessonStatus::Cancelled, $lesson->status);
        $this->assertSame('Parent booking was cancelled.', $lesson->metadata['cancellation_reason']);
    }

    public function test_admin_completing_the_booking_completes_the_lesson(): void
    {
        $lesson = $this->makeLesson();

        app(BookingServiceInterface::class)->complete($lesson->booking);

        $this->assertSame(LessonStatus::Completed, $lesson->refresh()->status);
    }

    public function test_admin_booking_no_show_finalizes_lesson_as_student_no_show(): void
    {
        $lesson = $this->makeLesson();

        app(BookingServiceInterface::class)->markNoShow($lesson->booking);

        $lesson->refresh();
        $this->assertSame(LessonStatus::StudentNoShow, $lesson->status);
        $this->assertSame(LessonAttendanceStatus::NoShow, $lesson->student_attendance_status);
    }

    // ── Auto-completion ──────────────────────────────────────────────

    public function test_auto_complete_command_finalizes_due_lessons(): void
    {
        $due = $this->makeLesson(endedHoursAgo: 48);

        $dueNoShow = $this->makeLesson(endedHoursAgo: 48);
        $this->lessons->markStudentAttendance($dueNoShow, LessonAttendanceStatus::NoShow);

        $insideGrace = $this->makeLesson(endedHoursAgo: 2);
        $upcoming = $this->makeLesson();

        $dueButDisputed = $this->makeLesson(endedHoursAgo: 48);
        $this->lessons->dispute($dueButDisputed, $dueButDisputed->student, 'Contested.');

        $this->artisan('lessons:auto-complete')
            ->expectsOutputToContain('Finalized 2 lesson(s).')
            ->assertSuccessful();

        $due->refresh();
        $this->assertSame(LessonStatus::Completed, $due->status);
        $this->assertNotNull($due->auto_completed_at);
        $this->assertNull($due->completed_by);
        // Auto-completion never asserts verified attendance.
        $this->assertSame(LessonAttendanceStatus::Pending, $due->student_attendance_status);
        $this->assertSame(BookingStatus::Completed, $due->booking->fresh()->status);

        $this->assertSame(LessonStatus::StudentNoShow, $dueNoShow->refresh()->status);
        $this->assertSame(LessonStatus::Scheduled, $insideGrace->refresh()->status);
        $this->assertSame(LessonStatus::Scheduled, $upcoming->refresh()->status);
        $this->assertSame(LessonStatus::Disputed, $dueButDisputed->refresh()->status);

        // Idempotent: a second sweep finds nothing left to finalize.
        $this->artisan('lessons:auto-complete')
            ->expectsOutputToContain('Finalized 0 lesson(s).')
            ->assertSuccessful();
    }

    public function test_auto_complete_respects_kill_switch(): void
    {
        $settings = app(LessonSettings::class);
        $settings->auto_complete_enabled = false;
        $settings->save();

        $due = $this->makeLesson(endedHoursAgo: 48);

        $this->artisan('lessons:auto-complete')
            ->expectsOutputToContain('Finalized 0 lesson(s).')
            ->assertSuccessful();

        $this->assertSame(LessonStatus::Scheduled, $due->refresh()->status);
    }

    public function test_auto_complete_skips_lessons_awaiting_required_confirmation(): void
    {
        $settings = app(LessonSettings::class);
        $settings->require_instructor_completion = true;
        $settings->save();

        $unconfirmed = $this->makeLesson(endedHoursAgo: 48);

        $confirmed = $this->makeLesson(endedHoursAgo: 48);
        $this->lessons->markInstructorAttendance($confirmed, LessonAttendanceStatus::Attended);

        $this->artisan('lessons:auto-complete')
            ->expectsOutputToContain('Finalized 1 lesson(s).')
            ->assertSuccessful();

        $this->assertSame(LessonStatus::Scheduled, $unconfirmed->refresh()->status);
        $this->assertSame(LessonStatus::Completed, $confirmed->refresh()->status);
    }

    // ── Data boundaries ──────────────────────────────────────────────

    public function test_lesson_metadata_is_whitelisted_and_free_of_payment_data(): void
    {
        $topic = SubjectTopic::factory()->create();

        $booking = Booking::factory()->confirmed()->paid(499.00, 'INR')->create([
            'payment_reference' => 'PAY-SECRET-REF',
            'meta' => [
                'subject' => $topic->subject->slug,
                'grade' => 7,
                'topic' => $topic->slug,
                'topic_id' => $topic->id,
                // Hostile extras that must never be copied onto the lesson.
                'payment_reference' => 'PAY-SECRET-REF',
                'provider' => 'razorpay',
                'wallet_balance' => 1000,
            ],
        ]);

        $lesson = $this->lessons->createFromBooking($booking);

        $allowed = ['booking_reference', 'booking_type', 'subject', 'topic', 'grade'];
        $this->assertEmpty(array_diff(array_keys($lesson->metadata), $allowed));

        $serialized = json_encode($lesson->toArray());
        $this->assertStringNotContainsString('PAY-SECRET-REF', $serialized);
        $this->assertStringNotContainsString('razorpay', $serialized);
        $this->assertStringNotContainsString('price', $serialized);
        $this->assertStringNotContainsString('wallet', $serialized);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function makeLesson(?int $endedHoursAgo = null): Lesson
    {
        $attributes = [];

        if ($endedHoursAgo !== null) {
            $endsAt = now()->subHours($endedHoursAgo)->startOfHour();
            $attributes = [
                'starts_at' => $endsAt->copy()->subMinutes(60),
                'ends_at' => $endsAt,
            ];
        }

        $booking = Booking::factory()->confirmed()->create($attributes);

        return $this->lessons->createFromBooking($booking);
    }
}
