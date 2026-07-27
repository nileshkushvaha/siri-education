<?php

declare(strict_types=1);

namespace Tests\Feature\Waitlist;

use App\Booking\DTOs\CancelBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Booking\Events\BookingCancelled;
use App\Booking\Events\BookingConfirmed;
use App\Booking\Events\BookingRescheduled;
use App\Enums\StudentStatus;
use App\Enums\WaitlistEntryStatus;
use App\Exceptions\Student\StudentActionNotAvailableException;
use App\Listeners\Waitlist\FulfillWaitlistOnBookingConfirmed;
use App\Listeners\Waitlist\ProcessWaitlistOnBookingCancelled;
use App\Listeners\Waitlist\ProcessWaitlistOnBookingRescheduled;
use App\Models\Booking;
use App\Models\InstructorWaitlistEntry;
use App\Models\User;
use App\Models\UserProfile;
use App\Notifications\Waitlist\InstructorAvailabilityOpenedNotification;
use App\Services\Instructor\InstructorAvailabilityService;
use App\Services\Instructor\InstructorOnboardingService;
use App\Settings\FeatureSettings;
use App\Waitlist\Events\InstructorAvailabilityOpened;
use App\Waitlist\Exceptions\WaitlistException;
use App\Waitlist\Services\WaitlistService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS §6.19/§10.28. Booking remains first-come, first-served
 * — there is no exclusive offer to test; notification is informational
 * and every currently-eligible Waiting entry for an instructor is
 * notified when a real opening event fires.
 */
class InstructorWaitlistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        app(FeatureSettings::class)->waitlist_enabled = true;
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function student(bool $active = true): User
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => $active ? StudentStatus::Active : StudentStatus::Suspended]);

        return $student;
    }

    private function instructor(string $status = 'approved'): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        UserProfile::updateOrCreate(['user_id' => $instructor->id], [
            'profile_visibility' => 'public',
            'instructor_status' => $status,
        ]);

        return $instructor;
    }

    private function service(): WaitlistService
    {
        return app(WaitlistService::class);
    }

    // ── Join ─────────────────────────────────────────────────────────

    public function test_eligible_student_joins_an_instructors_waitlist(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();

        $entry = $this->service()->join($student, $instructor);

        $this->assertSame(WaitlistEntryStatus::Waiting, $entry->status);
        $this->assertSame($student->id, $entry->student_user_id);
        $this->assertSame($instructor->id, $entry->instructor_user_id);
        $this->assertNotNull($entry->joined_at);
        $this->assertDatabaseHas('activity_log', ['event' => 'waitlist_joined']);
    }

    public function test_join_is_rejected_when_the_feature_flag_is_disabled(): void
    {
        app(FeatureSettings::class)->waitlist_enabled = false;

        $this->expectException(WaitlistException::class);
        $this->service()->join($this->student(), $this->instructor());
    }

    public function test_a_non_student_actor_cannot_join(): void
    {
        $notAStudent = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->expectException(AuthorizationException::class);
        $this->service()->join($notAStudent, $this->instructor());
    }

    public function test_a_suspended_students_join_is_rejected(): void
    {
        $this->expectException(StudentActionNotAvailableException::class);
        $this->service()->join($this->student(active: false), $this->instructor());
    }

    public function test_joining_an_ineligible_instructor_is_rejected(): void
    {
        $this->expectException(WaitlistException::class);
        $this->service()->join($this->student(), $this->instructor(status: 'suspended'));
    }

    public function test_vacationing_instructor_is_still_joinable(): void
    {
        // Vacation is exactly the scenario a waitlist serves — SRS's own
        // vacation-resume trigger only makes sense if joining is allowed
        // while an instructor is on vacation.
        $entry = $this->service()->join($this->student(), $this->instructor(status: 'vacation'));

        $this->assertSame(WaitlistEntryStatus::Waiting, $entry->status);
    }

    public function test_duplicate_active_join_is_rejected(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->service()->join($student, $instructor);

        $this->expectException(WaitlistException::class);
        $this->service()->join($student, $instructor);
    }

    public function test_the_database_constraint_rejects_a_duplicate_active_key_directly(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();

        InstructorWaitlistEntry::query()->create([
            'student_user_id' => $student->id,
            'instructor_user_id' => $instructor->id,
            'status' => WaitlistEntryStatus::Waiting,
            'active_key' => WaitlistService::activeKeyFor($student->id, $instructor->id),
            'joined_at' => now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        InstructorWaitlistEntry::query()->create([
            'student_user_id' => $student->id,
            'instructor_user_id' => $instructor->id,
            'status' => WaitlistEntryStatus::Waiting,
            'active_key' => WaitlistService::activeKeyFor($student->id, $instructor->id),
            'joined_at' => now(),
        ]);
    }

    public function test_historical_rejoin_after_withdrawal_creates_a_new_entry(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();

        $first = $this->service()->join($student, $instructor);
        $this->service()->leave($student, $first);

        $second = $this->service()->join($student, $instructor);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(WaitlistEntryStatus::Withdrawn, $first->fresh()->status);
        $this->assertSame(WaitlistEntryStatus::Waiting, $second->status);
        $this->assertSame(2, InstructorWaitlistEntry::query()->where('student_user_id', $student->id)->count());
    }

    public function test_entries_are_ordered_fifo_by_joined_at_then_id(): void
    {
        $instructor = $this->instructor();
        $first = $this->service()->join($this->student(), $instructor);
        $second = $this->service()->join($this->student(), $instructor);

        $ids = InstructorWaitlistEntry::query()
            ->where('instructor_user_id', $instructor->id)
            ->orderBy('joined_at')->orderBy('id')
            ->pluck('id')->all();

        $this->assertSame([$first->id, $second->id], $ids);
    }

    // ── Leave ────────────────────────────────────────────────────────

    public function test_owner_withdraws_their_own_entry(): void
    {
        $student = $this->student();
        $entry = $this->service()->join($student, $this->instructor());

        $withdrawn = $this->service()->leave($student, $entry);

        $this->assertSame(WaitlistEntryStatus::Withdrawn, $withdrawn->status);
        $this->assertNotNull($withdrawn->withdrawn_at);
        $this->assertNull($withdrawn->active_key);
        $this->assertDatabaseHas('activity_log', ['event' => 'waitlist_withdrawn']);
    }

    public function test_unrelated_student_cannot_withdraw_someone_elses_entry(): void
    {
        $entry = $this->service()->join($this->student(), $this->instructor());
        $otherStudent = $this->student();

        $this->expectException(AuthorizationException::class);
        $this->service()->leave($otherStudent, $entry);
    }

    public function test_repeated_withdrawal_is_idempotent(): void
    {
        $student = $this->student();
        $entry = $this->service()->join($student, $this->instructor());

        $this->service()->leave($student, $entry);
        $result = $this->service()->leave($student, $entry->fresh());

        $this->assertSame(WaitlistEntryStatus::Withdrawn, $result->status);
        $this->assertSame(1, InstructorWaitlistEntry::query()->count());
    }

    // ── Availability processing ─────────────────────────────────────

    public function test_processing_notifies_every_eligible_waiting_entry(): void
    {
        Notification::fake();
        $instructor = $this->instructor();
        $studentA = $this->student();
        $studentB = $this->student();
        $this->service()->join($studentA, $instructor);
        $this->service()->join($studentB, $instructor);

        $notified = $this->service()->processAvailabilityOpening($instructor, 'test_trigger', 'trigger-1');

        $this->assertSame(2, $notified);
        Notification::assertSentTo($studentA, InstructorAvailabilityOpenedNotification::class);
        Notification::assertSentTo($studentB, InstructorAvailabilityOpenedNotification::class);
    }

    public function test_ineligible_entries_are_transitioned_and_never_notified(): void
    {
        Notification::fake();
        $instructor = $this->instructor();
        $suspendedStudent = $this->student();
        $entry = $this->service()->join($suspendedStudent, $instructor);
        $suspendedStudent->profile()->update(['student_status' => StudentStatus::Suspended]);

        $notified = $this->service()->processAvailabilityOpening($instructor, 'test_trigger', 'trigger-1');

        $this->assertSame(0, $notified);
        $this->assertSame(WaitlistEntryStatus::Ineligible, $entry->fresh()->status);
        Notification::assertNotSentTo($suspendedStudent, InstructorAvailabilityOpenedNotification::class);
    }

    public function test_processing_does_nothing_when_the_instructor_is_not_currently_bookable(): void
    {
        $instructor = $this->instructor(status: 'vacation');
        $student = $this->student();
        $this->service()->join($student, $instructor);

        Notification::fake();
        $notified = $this->service()->processAvailabilityOpening($instructor, 'test_trigger', 'trigger-1');

        $this->assertSame(0, $notified);
        Notification::assertNotSentTo($student, InstructorAvailabilityOpenedNotification::class);
    }

    public function test_processing_does_nothing_when_the_feature_flag_is_disabled(): void
    {
        Notification::fake();
        $instructor = $this->instructor();
        $entry = $this->service()->join($this->student(), $instructor);
        app(FeatureSettings::class)->waitlist_enabled = false;

        $notified = $this->service()->processAvailabilityOpening($instructor, 'test_trigger', 'trigger-1');

        $this->assertSame(0, $notified);
        Notification::assertNotSentTo($entry->student, InstructorAvailabilityOpenedNotification::class);
    }

    public function test_repeated_processing_of_the_same_opening_is_idempotent(): void
    {
        Notification::fake();
        $instructor = $this->instructor();
        $entry = $this->service()->join($this->student(), $instructor);

        $first = $this->service()->processAvailabilityOpening($instructor, 'test_trigger', 'trigger-1');
        $second = $this->service()->processAvailabilityOpening($instructor, 'test_trigger', 'trigger-1');

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        Notification::assertSentToTimes($entry->student, InstructorAvailabilityOpenedNotification::class, 1);
    }

    public function test_a_genuinely_distinct_later_opening_notifies_again(): void
    {
        Notification::fake();
        $instructor = $this->instructor();
        $entry = $this->service()->join($this->student(), $instructor);

        $this->service()->processAvailabilityOpening($instructor, 'booking_cancelled', 'booking-1');
        $notified = $this->service()->processAvailabilityOpening($instructor, 'booking_cancelled', 'booking-2');

        $this->assertSame(1, $notified);
        Notification::assertSentToTimes($entry->student, InstructorAvailabilityOpenedNotification::class, 2);
    }

    // ── Trigger wiring ────────────────────────────────────────────────

    public function test_booking_cancelled_event_processes_the_instructors_waitlist(): void
    {
        Event::fake([InstructorAvailabilityOpened::class]);
        Notification::fake();
        $instructor = $this->instructor();
        $entry = $this->service()->join($this->student(), $instructor);

        $booking = Booking::factory()->create(['instructor_id' => $instructor->id]);
        (new ProcessWaitlistOnBookingCancelled(app(WaitlistService::class)))
            ->handle(new BookingCancelled($booking, new CancelBookingData(BookingActor::Student, 'test')));

        Notification::assertSentTo($entry->student, InstructorAvailabilityOpenedNotification::class);
    }

    public function test_booking_rescheduled_event_processes_the_instructors_waitlist(): void
    {
        Notification::fake();
        $instructor = $this->instructor();
        $entry = $this->service()->join($this->student(), $instructor);

        $booking = Booking::factory()->create(['instructor_id' => $instructor->id]);
        (new ProcessWaitlistOnBookingRescheduled(app(WaitlistService::class)))
            ->handle(new BookingRescheduled($booking, CarbonImmutable::now(), CarbonImmutable::now()->addHour()));

        Notification::assertSentTo($entry->student, InstructorAvailabilityOpenedNotification::class);
    }

    public function test_creating_an_active_availability_window_dispatches_the_opening_event(): void
    {
        Event::fake([InstructorAvailabilityOpened::class]);
        $instructor = $this->instructor();

        app(InstructorAvailabilityService::class)->create([
            'teacher_id' => $instructor->id,
            'day_of_week' => Weekday::Monday,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'is_active' => true,
        ], $instructor);

        Event::assertDispatched(InstructorAvailabilityOpened::class, fn ($e): bool => $e->instructor->id === $instructor->id && $e->reason === 'availability_created');
    }

    public function test_creating_an_inactive_draft_availability_window_does_not_dispatch(): void
    {
        Event::fake([InstructorAvailabilityOpened::class]);
        $instructor = $this->instructor();

        app(InstructorAvailabilityService::class)->create([
            'teacher_id' => $instructor->id,
            'day_of_week' => Weekday::Monday,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'is_active' => false,
        ], $instructor);

        Event::assertNotDispatched(InstructorAvailabilityOpened::class);
    }

    public function test_resuming_from_vacation_dispatches_the_opening_event(): void
    {
        Event::fake([InstructorAvailabilityOpened::class]);
        $instructor = $this->instructor(status: 'vacation');

        app(InstructorOnboardingService::class)->resumeFromVacation($instructor, $instructor);

        Event::assertDispatched(InstructorAvailabilityOpened::class, fn ($e): bool => $e->instructor->id === $instructor->id && $e->reason === 'vacation_resumed');
    }

    // ── Booking integration / fulfillment ────────────────────────────

    public function test_a_confirmed_booking_fulfils_the_students_own_waiting_entry(): void
    {
        $instructor = $this->instructor();
        $student = $this->student();
        $entry = $this->service()->join($student, $instructor);

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'status' => BookingStatus::Confirmed,
        ]);

        $this->service()->fulfillForBooking($booking);

        $fresh = $entry->fresh();
        $this->assertSame(WaitlistEntryStatus::Fulfilled, $fresh->status);
        $this->assertSame($booking->id, $fresh->fulfilled_booking_id);
        $this->assertNotNull($fresh->fulfilled_at);
    }

    public function test_a_booking_with_no_waitlist_entry_is_a_safe_no_op(): void
    {
        $instructor = $this->instructor();
        $student = $this->student();

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'status' => BookingStatus::Confirmed,
        ]);

        $this->service()->fulfillForBooking($booking);

        $this->assertSame(0, InstructorWaitlistEntry::query()->count());
    }

    public function test_a_different_students_booking_never_fulfils_another_students_entry(): void
    {
        $instructor = $this->instructor();
        $waitingStudent = $this->student();
        $entry = $this->service()->join($waitingStudent, $instructor);

        $anotherStudent = $this->student();
        $booking = Booking::factory()->create([
            'student_id' => $anotherStudent->id,
            'instructor_id' => $instructor->id,
            'status' => BookingStatus::Confirmed,
        ]);

        $this->service()->fulfillForBooking($booking);

        $this->assertSame(WaitlistEntryStatus::Waiting, $entry->fresh()->status);
    }

    public function test_booking_confirmed_event_triggers_fulfillment(): void
    {
        $instructor = $this->instructor();
        $student = $this->student();
        $entry = $this->service()->join($student, $instructor);

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'status' => BookingStatus::Confirmed,
        ]);

        (new FulfillWaitlistOnBookingConfirmed(app(WaitlistService::class)))
            ->handle(new BookingConfirmed($booking));

        $this->assertSame(WaitlistEntryStatus::Fulfilled, $entry->fresh()->status);
    }

    // ── UI / authorization ────────────────────────────────────────────

    public function test_join_and_leave_routes_require_authentication(): void
    {
        $instructor = $this->instructor();

        $this->post(route('dashboard.waitlist.store', $instructor))->assertRedirect(route('auth.login'));
        $this->delete(route('dashboard.waitlist.destroy', $instructor))->assertRedirect(route('auth.login'));
    }

    public function test_authenticated_student_can_join_and_leave_via_the_route(): void
    {
        $student = $this->student();
        $student->profile()->update(['student_status' => StudentStatus::Active]);
        $instructor = $this->instructor();

        $this->actingAs($student)
            ->post(route('dashboard.waitlist.store', $instructor))
            ->assertRedirect();

        $this->assertDatabaseHas('instructor_waitlist_entries', [
            'student_user_id' => $student->id,
            'instructor_user_id' => $instructor->id,
            'status' => 'waiting',
        ]);

        $this->actingAs($student)
            ->delete(route('dashboard.waitlist.destroy', $instructor))
            ->assertRedirect();

        $this->assertDatabaseHas('instructor_waitlist_entries', [
            'student_user_id' => $student->id,
            'instructor_user_id' => $instructor->id,
            'status' => 'withdrawn',
        ]);
    }

    public function test_unauthorized_admin_cannot_access_the_waitlist_admin_resource(): void
    {
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager->assignRole('manager');

        $this->actingAs($manager)
            ->get('/admin/instructor-waitlist-entries')
            ->assertForbidden();
    }

    public function test_authorized_admin_can_view_the_waitlist_admin_resource(): void
    {
        $entry = $this->service()->join($this->student(), $this->instructor());

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'ViewAny:InstructorWaitlistEntry', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'View:InstructorWaitlistEntry', 'guard_name' => 'web']);
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');
        $manager->givePermissionTo(['ViewAny:InstructorWaitlistEntry', 'View:InstructorWaitlistEntry']);

        $this->actingAs($manager)
            ->get('/admin/instructor-waitlist-entries')
            ->assertOk();

        $this->actingAs($manager)
            ->get('/admin/instructor-waitlist-entries/'.$entry->id)
            ->assertOk();
    }

    public function test_student_waitlist_list_query_is_bounded(): void
    {
        $student = $this->student();
        $instructor1 = $this->instructor();
        $instructor2 = $this->instructor();
        $this->service()->join($student, $instructor1);
        $this->service()->join($student, $instructor2);

        DB::enableQueryLog();
        InstructorWaitlistEntry::query()->where('student_user_id', $student->id)->with('instructor')->latest('joined_at')->paginate(10);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(4, $count);
    }
}
