<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\MeetingJoinAvailability;
use App\Booking\Events\MeetingUpdated;
use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Http\Resources\Student\StudentBookingResource;
use App\Listeners\Booking\SendMeetingNotifications;
use App\Livewire\Frontend\Student\BookingHistory;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Models\BookingType;
use App\Models\User;
use App\Notifications\Booking\MeetingUpdatedNotification;
use App\Services\Student\StudentDashboardService;
use App\Services\Student\StudentLifecycleService;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * BookingMeetingService::studentJoinUrlFor() is THE authoritative
 * student meeting-URL accessor. A meeting URL is a
 * sensitive access credential — no first-party service/UI/API path may
 * return it to a student actor unless the viewer owns the booking as
 * its student AND passes the strict Active lifecycle guard AND the
 * existing visibility/status rules pass. Instructor and admin paths
 * are untouched, and no meeting row is ever mutated by enforcement.
 */
class StudentMeetingLinkAccessTest extends TestCase
{
    use RefreshDatabase;

    private const string JOIN_URL = 'https://meet.example.test/abc';

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $settings = app(MeetingSettings::class);
        $settings->student_join_url_visible = true;
        $settings->instructor_join_url_visible = true;
        $settings->save();
    }

    /** @return array<string, array{0: StudentStatus|null}> */
    public static function nonActiveStatuses(): array
    {
        return [
            'registered' => [StudentStatus::Registered],
            'suspended' => [StudentStatus::Suspended],
            'archived' => [StudentStatus::Archived],
            'null status' => [null],
        ];
    }

    private function studentWith(?StudentStatus $status): User
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        if ($status !== null) {
            $student->profile()->update(['student_status' => $status]);
        }

        return $student;
    }

    /** @return array{0: Booking, 1: User, 2: User} */
    private function confirmedBookingWithMeeting(?StudentStatus $studentStatus = StudentStatus::Active): array
    {
        $student = $this->studentWith($studentStatus);
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $instructor->profile()->update(['instructor_status' => InstructorStatus::Active]);

        $type = BookingType::factory()->create(['key' => 'free_demo', 'duration_minutes' => 30]);

        $booking = Booking::factory()->for($type, 'type')->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => now()->addMinutes(10),
            'ends_at' => now()->addMinutes(40),
        ]);

        BookingMeeting::factory()->created(self::JOIN_URL)->create([
            'booking_id' => $booking->id,
            'starts_at' => now()->addMinutes(10),
            'ends_at' => now()->addMinutes(40),
        ]);

        return [$booking->fresh(), $student, $instructor];
    }

    private function service(): BookingMeetingServiceInterface
    {
        return app(BookingMeetingServiceInterface::class);
    }

    // ── 1. Active owner receives the URL ─────────────────────────────────────

    public function test_direct_service_call_returns_the_url_for_an_active_booked_student(): void
    {
        [$booking, $student] = $this->confirmedBookingWithMeeting();

        $this->assertSame(self::JOIN_URL, $this->service()->studentJoinUrlFor($booking, $student));
    }

    // ── 2-6/19. Every non-Active state fails closed at the service itself ────

    #[DataProvider('nonActiveStatuses')]
    public function test_direct_service_call_returns_no_url_for_a_non_active_student(?StudentStatus $status): void
    {
        [$booking, $student] = $this->confirmedBookingWithMeeting($status);

        $this->assertNull($this->service()->studentJoinUrlFor($booking, $student));
    }

    public function test_direct_service_call_returns_no_url_for_a_missing_profile(): void
    {
        [$booking, $student] = $this->confirmedBookingWithMeeting();
        $student->profile()->delete();

        $this->assertNull($this->service()->studentJoinUrlFor($booking, $student->fresh()));
    }

    // ── 7/11. Ownership: non-owner and unauthorized viewers get nothing ─────

    public function test_a_non_owner_active_student_cannot_receive_the_url(): void
    {
        [$booking] = $this->confirmedBookingWithMeeting();
        $otherActiveStudent = $this->studentWith(StudentStatus::Active);

        $this->assertNull($this->service()->studentJoinUrlFor($booking, $otherActiveStudent));
    }

    public function test_an_unauthorized_unrelated_user_receives_no_url(): void
    {
        [$booking] = $this->confirmedBookingWithMeeting();
        $stranger = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->assertNull($this->service()->studentJoinUrlFor($booking, $stranger));
        $this->assertNull($this->service()->studentJoinUrlFor($booking, null));
    }

    // ── 8/9. Instructor path unchanged; dual-role evaluated by ownership ────

    public function test_the_assigned_instructor_retains_existing_join_access(): void
    {
        [$booking] = $this->confirmedBookingWithMeeting();

        $this->assertSame(
            MeetingJoinAvailability::Available,
            $this->service()->joinAvailabilityFor($booking, roleVisible: true),
        );
        $this->assertSame(self::JOIN_URL, $this->service()->joinUrlFor($booking, roleVisible: true));
    }

    /** A dual-role instructor (who also carries a Suspended student profile) is evaluated by booking ownership: their instructor access is untouched, and as a non-owner they get nothing from the student path. */
    public function test_a_dual_role_instructor_viewer_is_evaluated_by_booking_ownership(): void
    {
        [$booking, , $instructor] = $this->confirmedBookingWithMeeting();

        $instructor->assignRole('student');
        $instructor->profile()->update(['student_status' => StudentStatus::Suspended]);

        // Instructor path (their own booking, instructor role): unchanged.
        $this->assertSame(self::JOIN_URL, $this->service()->joinUrlFor($booking->fresh(), roleVisible: true));

        // Student path: they are not this booking's student — ownership
        // fails before any lifecycle question arises.
        $this->assertNull($this->service()->studentJoinUrlFor($booking->fresh(), $instructor->fresh()));
    }

    // ── 12/13. Existing visibility/status rules still apply ──────────────────

    public function test_the_student_visibility_setting_still_gates_the_url(): void
    {
        [$booking, $student] = $this->confirmedBookingWithMeeting();

        $settings = app(MeetingSettings::class);
        $settings->student_join_url_visible = false;
        $settings->save();

        $this->assertNull($this->service()->studentJoinUrlFor($booking, $student));
    }

    public function test_a_cancelled_booking_returns_no_url(): void
    {
        [$booking, $student] = $this->confirmedBookingWithMeeting();
        $booking->update(['status' => BookingStatus::Cancelled]);

        $this->assertNull($this->service()->studentJoinUrlFor($booking->fresh(), $student));
    }

    public function test_instructor_timing_window_rules_are_unchanged(): void
    {
        [$booking] = $this->confirmedBookingWithMeeting();
        $booking->meeting->update(['starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour()]);

        $this->assertSame(
            MeetingJoinAvailability::TooEarly,
            $this->service()->joinAvailabilityFor($booking->fresh(), roleVisible: true),
        );
    }

    // ── 24H.2B 1-6/20. Time-window boundaries (frozen clock, single calc) ───

    /**
     * Boundary semantics under the existing joinAvailabilityFor() rules
     * (defaults: 15 min before, 60 min after): earliest visible instant
     * = starts_at - before (INCLUSIVE: now()->lt() throws TooEarly only
     * strictly before it); latest = ends_at + after (INCLUSIVE:
     * now()->gt() rejects only strictly after it). All comparisons are
     * absolute instants — display timezones never shift the window.
     */
    public function test_window_boundaries_are_enforced_and_inclusive(): void
    {
        Carbon::setTestNow('2026-07-19 12:00:00');

        try {
            [$booking, $student] = $this->confirmedBookingWithMeeting();
            $booking->meeting->update(['starts_at' => now()->addMinutes(30), 'ends_at' => now()->addMinutes(60)]);
            $booking = $booking->fresh();

            $settings = app(MeetingSettings::class);
            $earliest = $booking->meeting->starts_at->copy()->subMinutes($settings->meeting_link_visible_before_minutes);
            $latest = $booking->meeting->ends_at->copy()->addMinutes($settings->meeting_link_visible_after_minutes);

            // 2. One second before the earliest boundary: no URL.
            Carbon::setTestNow($earliest->copy()->subSecond());
            $this->assertNull($this->service()->studentJoinUrlFor($booking, $student));

            // 3. Exactly at the earliest boundary: inclusive — URL released.
            Carbon::setTestNow($earliest);
            $this->assertSame(self::JOIN_URL, $this->service()->studentJoinUrlFor($booking, $student));

            // 1. Well inside the window: URL released.
            Carbon::setTestNow($booking->meeting->starts_at);
            $this->assertSame(self::JOIN_URL, $this->service()->studentJoinUrlFor($booking, $student));

            // 4. Exactly at the latest boundary: inclusive — URL released.
            Carbon::setTestNow($latest);
            $this->assertSame(self::JOIN_URL, $this->service()->studentJoinUrlFor($booking, $student));

            // 5. One second after the latest boundary: no URL.
            Carbon::setTestNow($latest->copy()->addSecond());
            $this->assertNull($this->service()->studentJoinUrlFor($booking, $student));
        } finally {
            Carbon::setTestNow();
        }
    }

    /** 6. Zero before/after collapses the window to exactly [starts_at, ends_at], boundaries still inclusive. */
    public function test_zero_window_settings_follow_existing_semantics(): void
    {
        Carbon::setTestNow('2026-07-19 12:00:00');

        try {
            $settings = app(MeetingSettings::class);
            $settings->meeting_link_visible_before_minutes = 0;
            $settings->meeting_link_visible_after_minutes = 0;
            $settings->save();

            [$booking, $student] = $this->confirmedBookingWithMeeting();
            $booking->meeting->update(['starts_at' => now()->addMinutes(30), 'ends_at' => now()->addMinutes(60)]);
            $booking = $booking->fresh();

            Carbon::setTestNow($booking->meeting->starts_at->copy()->subSecond());
            $this->assertNull($this->service()->studentJoinUrlFor($booking, $student));

            Carbon::setTestNow($booking->meeting->starts_at);
            $this->assertSame(self::JOIN_URL, $this->service()->studentJoinUrlFor($booking, $student));

            Carbon::setTestNow($booking->meeting->ends_at);
            $this->assertSame(self::JOIN_URL, $this->service()->studentJoinUrlFor($booking, $student));

            Carbon::setTestNow($booking->meeting->ends_at->copy()->addSecond());
            $this->assertNull($this->service()->studentJoinUrlFor($booking, $student));
        } finally {
            Carbon::setTestNow();
        }
    }

    /** 7 (24H.2B). Non-Active statuses stay rejected even INSIDE the window — lifecycle and window are both enforced. */
    #[DataProvider('nonActiveStatuses')]
    public function test_non_active_student_gets_no_url_even_inside_the_window(?StudentStatus $status): void
    {
        [$booking, $student] = $this->confirmedBookingWithMeeting($status);

        // Fixture places the meeting inside the window already.
        $this->assertNull($this->service()->studentJoinUrlFor($booking, $student));
    }

    // ── 24H.2B 9-13/15. Surfaces outside the window ─────────────────────────

    public function test_booking_history_contains_no_url_before_the_window(): void
    {
        [$booking, $student] = $this->confirmedBookingWithMeeting();
        $booking->meeting->update(['starts_at' => now()->addHours(5), 'ends_at' => now()->addHours(6)]);

        Livewire::actingAs($student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->assertDontSee(self::JOIN_URL);
    }

    public function test_json_resource_omits_url_and_password_outside_the_window(): void
    {
        [$booking, $student] = $this->confirmedBookingWithMeeting();
        $booking->meeting->update(['starts_at' => now()->addHours(5), 'ends_at' => now()->addHours(6), 'password' => 'p4ss']);

        $request = Request::create('/api/test');
        $request->setUserResolver(fn () => $student);

        $payload = collect((new StudentBookingResource($booking->fresh()))->toArray($request))
            ->reject(fn ($v) => $v instanceof MissingValue)->all();

        $this->assertArrayNotHasKey('meeting_url', $payload);
        $this->assertArrayNotHasKey('meeting_password', $payload);
        $this->assertStringNotContainsString(self::JOIN_URL, (string) json_encode($payload));
    }

    public function test_dashboard_payload_contains_no_url_outside_the_window(): void
    {
        [$booking, $student] = $this->confirmedBookingWithMeeting();
        $booking->meeting->update(['starts_at' => now()->addHours(5), 'ends_at' => now()->addHours(6)]);

        $summary = app(StudentDashboardService::class)->summary($student->fresh());

        $this->assertNotNull($summary->nextLesson);
        $this->assertNull($summary->nextLesson['join_url']);
        $this->assertFalse($summary->nextLesson['join_window_open']);
        $this->assertStringNotContainsString(self::JOIN_URL, (string) json_encode($summary->nextLesson));
    }

    /** 13 (24H.2B). Meeting-created student notification delivered too early carries no URL/password — a platform link instead; the instructor copy is unchanged. */
    public function test_too_early_student_notification_contains_no_url_but_links_to_the_platform(): void
    {
        Notification::fake();

        [$booking, $student, $instructor] = $this->confirmedBookingWithMeeting();
        $booking->meeting->update(['starts_at' => now()->addHours(5), 'ends_at' => now()->addHours(6), 'password' => 'p4ss']);
        $booking = $booking->fresh();

        app(SendMeetingNotifications::class)->handleUpdated(new MeetingUpdated($booking, $booking->meeting));

        Notification::assertSentTo($student, MeetingUpdatedNotification::class, function (MeetingUpdatedNotification $notification) use ($student): bool {
            $mail = $notification->toMail($student);
            $serialized = (string) json_encode($mail);

            return $notification->includeJoinUrl === false
                && ! str_contains($serialized, self::JOIN_URL)
                && ! str_contains($serialized, 'p4ss')
                && $mail->actionUrl === route('dashboard.my-bookings')
                && ! str_contains($notification->toSms($student), self::JOIN_URL);
        });

        Notification::assertSentTo($instructor, MeetingUpdatedNotification::class, fn (MeetingUpdatedNotification $n): bool => $n->includeJoinUrl === true);
    }

    /** 14 (24H.2B). Inside the window, the student notification keeps its existing URL inclusion. */
    public function test_in_window_student_notification_still_includes_the_url(): void
    {
        Notification::fake();

        [$booking, $student] = $this->confirmedBookingWithMeeting();

        app(SendMeetingNotifications::class)->handleUpdated(new MeetingUpdated($booking, $booking->meeting));

        Notification::assertSentTo($student, MeetingUpdatedNotification::class, function (MeetingUpdatedNotification $notification) use ($student): bool {
            return $notification->includeJoinUrl === true
                && $notification->toMail($student)->actionUrl === self::JOIN_URL;
        });
    }

    // ── 15. Stale request after suspension receives no URL ───────────────────

    public function test_a_stale_request_after_suspension_receives_no_url(): void
    {
        [$booking, $student] = $this->confirmedBookingWithMeeting();

        $this->assertSame(self::JOIN_URL, $this->service()->studentJoinUrlFor($booking, $student));

        $this->suspend($student);

        // Same in-memory objects a stale Livewire component would hold —
        // the guard's fresh DB read rejects regardless.
        $this->assertNull($this->service()->studentJoinUrlFor($booking, $student));
    }

    // ── 16. Notification contains no URL for an ineligible student ──────────

    public function test_meeting_update_notification_withholds_url_from_a_suspended_student_but_not_the_instructor(): void
    {
        Notification::fake();

        [$booking, $student, $instructor] = $this->confirmedBookingWithMeeting();
        $this->suspend($student);

        app(SendMeetingNotifications::class)->handleUpdated(new MeetingUpdated($booking->fresh(), $booking->meeting->fresh()));

        Notification::assertSentTo($student, MeetingUpdatedNotification::class, function (MeetingUpdatedNotification $notification) use ($student): bool {
            $mailText = json_encode($notification->toMail($student));

            return $notification->includeJoinUrl === false
                && ! str_contains((string) $mailText, self::JOIN_URL)
                && ! str_contains($notification->toDatabase($student)['message'] ?? '', self::JOIN_URL)
                && ! str_contains($notification->toSms($student), self::JOIN_URL);
        });

        Notification::assertSentTo($instructor, MeetingUpdatedNotification::class, fn (MeetingUpdatedNotification $notification): bool => $notification->includeJoinUrl === true);
    }

    // ── 17/18. No meeting mutation, no provider call ─────────────────────────

    public function test_enforcement_never_mutates_the_meeting_row_and_makes_no_provider_call(): void
    {
        Http::fake();

        [$booking, $student] = $this->confirmedBookingWithMeeting();
        $original = $booking->meeting->fresh()->getAttributes();

        $this->suspend($student);
        $this->service()->studentJoinUrlFor($booking->fresh(), $student->fresh());

        $this->assertSame($original, $booking->meeting->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    // ── 14. BookingHistory renders no provider URL (service-driven) ─────────

    #[DataProvider('nonActiveStatuses')]
    public function test_booking_history_renders_no_url_for_a_non_active_student(?StudentStatus $status): void
    {
        [$booking, $student] = $this->confirmedBookingWithMeeting($status);

        Livewire::actingAs($student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->assertDontSee(self::JOIN_URL);
    }

    public function test_booking_history_renders_the_url_for_an_active_student(): void
    {
        [$booking, $student] = $this->confirmedBookingWithMeeting();

        Livewire::actingAs($student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->assertSee(self::JOIN_URL);
    }

    // ── Resource serialization ───────────────────────────────────────────────

    public function test_student_booking_resource_omits_the_url_key_entirely_for_a_suspended_student(): void
    {
        [$booking, $student] = $this->confirmedBookingWithMeeting(StudentStatus::Suspended);

        $request = Request::create('/api/test');
        $request->setUserResolver(fn () => $student);

        $payload = (new StudentBookingResource($booking))->toArray($request);
        $resolved = collect($payload)->filter(fn ($value) => ! $value instanceof MissingValue)->all();

        $this->assertArrayNotHasKey('meeting_url', $resolved);
        $this->assertArrayNotHasKey('meeting_password', $resolved);
        $this->assertStringNotContainsString(self::JOIN_URL, (string) json_encode($resolved));
    }

    public function test_student_booking_resource_includes_the_url_for_an_active_student(): void
    {
        [$booking, $student] = $this->confirmedBookingWithMeeting();

        $request = Request::create('/api/test');
        $request->setUserResolver(fn () => $student);

        $payload = (new StudentBookingResource($booking))->toArray($request);

        $this->assertSame(self::JOIN_URL, $payload['meeting_url']);
    }

    private function suspend(User $student): void
    {
        Permission::firstOrCreate(['name' => StudentLifecycleService::SUSPEND_PERMISSION, 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo(StudentLifecycleService::SUSPEND_PERMISSION);

        app(StudentLifecycleService::class)->suspend($student, $admin, 'Meeting-access test suspension.');
    }
}
