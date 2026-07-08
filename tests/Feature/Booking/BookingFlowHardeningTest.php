<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\GuestBookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\DTOs\GuestBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\DuplicateBookingException;
use App\Booking\Exceptions\SlotUnavailableException;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Livewire\Frontend\Booking\BookingWizard;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Holiday;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\TeacherUnavailability;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Settings\BookingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BookingFlowHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $student;

    private BookingType $demoType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->makeTeacher('maths');
        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        // Narrow 09:00-11:00 window (not the usual 09-17) so an "outside
        // availability" slot at 14:00 is easy to construct.
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()
                ->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)
                ->between('09:00:00', '11:00:00')
                ->create();
        }

        $this->demoType = BookingType::factory()->create([
            'key' => 'free_demo',
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
            'max_attendees' => 1,
        ]);
    }

    private function makeTeacher(string $subject, string $instructorStatus = 'approved'): User
    {
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $teacher->id], ['instructor_status' => $instructorStatus]);
        TeacherSubject::factory()->state(['teacher_id' => $teacher->id])->subject($subject, 1, 12)->create();

        return $teacher;
    }

    /** A slot guaranteed inside the 09:00-11:00 window, N days ahead. */
    private function slot(int $daysAhead = 3, int $hour = 9): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->addDays($daysAhead)->setTime($hour, 0);
    }

    private function bookingData(User $teacher, ?User $attendee, CarbonImmutable $startsAt, int $duration = 30): CreateBookingData
    {
        return new CreateBookingData(
            typeKey: 'free_demo',
            attendeeId: $attendee?->id,
            hostId: $teacher->id,
            startsAt: $startsAt,
            durationMinutes: $duration,
            guestName: $attendee ? null : 'Guest',
            guestEmail: $attendee ? null : 'guest-'.uniqid().'@example.com',
        );
    }

    // ── Slot consumption ────────────────────────────────────────────────

    public function test_generated_slot_can_be_booked(): void
    {
        $booking = app(BookingServiceInterface::class)->request(
            $this->bookingData($this->teacher, $this->student, $this->slot()),
        );

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'host_id' => $this->teacher->id]);
    }

    public function test_stale_slot_cannot_be_booked(): void
    {
        $slot = $this->slot();
        app(BookingServiceInterface::class)->request($this->bookingData($this->teacher, $this->student, $slot));

        $otherStudent = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->expectException(SlotUnavailableException::class);
        app(BookingServiceInterface::class)->request($this->bookingData($this->teacher, $otherStudent, $slot));
    }

    public function test_slot_outside_availability_cannot_be_booked(): void
    {
        $this->expectException(SlotUnavailableException::class);

        app(BookingServiceInterface::class)->request(
            $this->bookingData($this->teacher, $this->student, $this->slot(hour: 14)),
        );
    }

    public function test_slot_during_time_off_cannot_be_booked(): void
    {
        $slot = $this->slot();
        TeacherUnavailability::factory()->state([
            'teacher_id' => $this->teacher->id,
            'starts_at' => $slot->copy()->startOfDay(),
            'ends_at' => $slot->copy()->endOfDay(),
        ])->create();

        $this->expectException(SlotUnavailableException::class);
        app(BookingServiceInterface::class)->request($this->bookingData($this->teacher, $this->student, $slot));
    }

    public function test_slot_on_holiday_cannot_be_booked(): void
    {
        $slot = $this->slot();
        Holiday::factory()->create(['date' => $slot->toDateString()]);

        $this->expectException(SlotUnavailableException::class);
        app(BookingServiceInterface::class)->request($this->bookingData($this->teacher, $this->student, $slot));
    }

    public function test_slot_overlapping_existing_booking_cannot_be_booked(): void
    {
        $slot = $this->slot();
        app(BookingServiceInterface::class)->request($this->bookingData($this->teacher, $this->student, $slot));

        $otherStudent = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $overlapping = $slot->addMinutes(15);

        $this->expectException(SlotUnavailableException::class);
        app(BookingServiceInterface::class)->request($this->bookingData($this->teacher, $otherStudent, $overlapping));
    }

    public function test_buffer_rules_are_enforced(): void
    {
        BookingType::query()->where('key', 'free_demo')->update(['buffer_minutes' => 15]);

        $slot = $this->slot();
        app(BookingServiceInterface::class)->request($this->bookingData($this->teacher, $this->student, $slot));

        // Starts exactly when the first booking ends — still inside the 15-minute buffer.
        $otherStudent = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $backToBack = $slot->addMinutes(30);

        $this->expectException(SlotUnavailableException::class);
        app(BookingServiceInterface::class)->request($this->bookingData($this->teacher, $otherStudent, $backToBack));
    }

    public function test_min_notice_is_enforced(): void
    {
        $settings = app(BookingSettings::class);
        $settings->minimum_booking_notice_minutes = 120;
        $settings->save();

        $this->expectException(BookingException::class);

        app(BookingServiceInterface::class)->request(
            $this->bookingData($this->teacher, $this->student, CarbonImmutable::now('UTC')->addMinutes(30)),
        );
    }

    public function test_max_advance_is_enforced(): void
    {
        $settings = app(BookingSettings::class);
        $settings->maximum_advance_booking_days = 10;
        $settings->save();

        $this->expectException(BookingException::class);

        app(BookingServiceInterface::class)->request(
            $this->bookingData($this->teacher, $this->student, $this->slot(daysAhead: 30)),
        );
    }

    public function test_daily_cap_is_enforced(): void
    {
        $settings = app(BookingSettings::class);
        $settings->max_daily_bookings_per_teacher = 1;
        $settings->save();

        $slot = $this->slot();
        app(BookingServiceInterface::class)->request($this->bookingData($this->teacher, $this->student, $slot));

        $otherStudent = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $laterSameDay = $slot->addHour();

        $this->expectException(SlotUnavailableException::class);
        app(BookingServiceInterface::class)->request($this->bookingData($this->teacher, $otherStudent, $laterSameDay));
    }

    public function test_non_bookable_instructor_cannot_be_booked(): void
    {
        $rejected = $this->makeTeacher('science', 'rejected');
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $rejected->id])->forDay($day)->between('09:00:00', '11:00:00')->create();
        }

        $this->expectException(BookingException::class);

        app(GuestBookingServiceInterface::class)->book(new GuestBookingData(
            typeKey: 'free_demo',
            subject: 'science',
            grade: 5,
            startsAt: $this->slot(),
            timezone: 'UTC',
            guestName: 'Guest',
            guestEmail: 'guest@example.com',
            teacherId: $rejected->id,
        ));
    }

    // ── Race safety ──────────────────────────────────────────────────────

    public function test_concurrent_double_booking_attempt_is_blocked(): void
    {
        $slot = $this->slot();
        app(BookingServiceInterface::class)->request($this->bookingData($this->teacher, $this->student, $slot));

        // Same attendee, same type, same slot — the duplicate guard, not the overlap guard.
        $this->expectException(DuplicateBookingException::class);
        app(BookingServiceInterface::class)->request($this->bookingData($this->teacher, $this->student, $slot));
    }

    public function test_final_availability_check_runs_even_when_caller_precheck_is_bypassed(): void
    {
        $rejected = $this->makeTeacher('science', 'rejected');
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $rejected->id])->forDay($day)->between('09:00:00', '11:00:00')->create();
        }

        // Call BookingService directly, skipping GuestBookingService's own
        // eligibility pre-check entirely. It must still refuse: the
        // bookable-host guard lives inside AvailabilityService::ensureAvailable(),
        // which both the pre-lock TeacherAvailabilityRule and the in-lock
        // re-check call — so the guard is not solely the caller's
        // responsibility. This does not by itself prove which of the two
        // ensureAvailable() call sites threw (the fast-fail rule runs
        // first and would always catch it here), only that the service
        // itself — not just the caller — enforces the rule.
        $this->expectException(SlotUnavailableException::class);

        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            attendeeId: null,
            hostId: $rejected->id,
            startsAt: $this->slot(),
            durationMinutes: 30,
            guestName: 'Guest',
            guestEmail: 'guest-race@example.com',
        ));
    }

    public function test_host_lock_prevents_two_different_attendees_taking_the_same_slot(): void
    {
        $slot = $this->slot();
        $studentA = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $studentB = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        app(BookingServiceInterface::class)->request($this->bookingData($this->teacher, $studentA, $slot));

        $this->expectException(SlotUnavailableException::class);
        app(BookingServiceInterface::class)->request($this->bookingData($this->teacher, $studentB, $slot));
    }

    // ── Marketplace handoff ──────────────────────────────────────────────

    public function test_wizard_does_not_silently_switch_locked_instructor_across_steps(): void
    {
        UserProfile::query()->where('user_id', $this->teacher->id)->update(['profile_visibility' => 'public']);
        BookingType::query()->where('key', 'free_demo')->update(['sort_order' => 1]);
        Livewire::component('frontend.booking.booking-wizard', BookingWizard::class);

        Livewire::withQueryParams(['instructor' => $this->teacher->slug, 'type' => 'free_demo', 'subject' => 'maths'])
            ->test('frontend.booking.booking-wizard')
            ->assertSet('lockedInstructorId', $this->teacher->id)
            ->call('selectGrade', 5)
            ->assertSet('lockedInstructorId', $this->teacher->id)
            ->call('selectDate', $this->slot()->toDateString())
            ->assertSet('lockedInstructorId', $this->teacher->id)
            ->call('selectSlot', $this->slot()->toIso8601String())
            ->assertSet('lockedInstructorId', $this->teacher->id);
    }

    public function test_crafted_request_cannot_replace_locked_instructor(): void
    {
        UserProfile::query()->where('user_id', $this->teacher->id)->update(['profile_visibility' => 'public']);
        $otherTeacher = $this->makeTeacher('maths');
        Livewire::component('frontend.booking.booking-wizard', BookingWizard::class);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::withQueryParams(['instructor' => $this->teacher->slug])
            ->test('frontend.booking.booking-wizard')
            ->set('lockedInstructorId', $otherTeacher->id);
    }

    // ── Guest booking ────────────────────────────────────────────────────

    public function test_guest_can_request_a_safe_booking(): void
    {
        $response = $this->postJson('/api/v1/guest/bookings', [
            'type' => 'free_demo',
            'subject' => 'maths',
            'grade' => 5,
            'starts_at' => $this->slot()->toIso8601String(),
            'name' => 'Guest Parent',
            'email' => 'guest-safe@example.com',
        ])->assertCreated();

        $this->assertDatabaseHas('bookings', ['reference' => $response->json('data.reference')]);
    }

    public function test_guest_cannot_book_invalid_or_stale_slot(): void
    {
        $slot = $this->slot();
        app(BookingServiceInterface::class)->request($this->bookingData($this->teacher, $this->student, $slot));

        $this->postJson('/api/v1/guest/bookings', [
            'type' => 'free_demo',
            'subject' => 'maths',
            'grade' => 5,
            'starts_at' => $slot->toIso8601String(),
            'name' => 'Guest Parent',
            'email' => 'guest-stale@example.com',
        ])->assertStatus(422);
    }

    public function test_guest_required_fields_are_validated(): void
    {
        $this->postJson('/api/v1/guest/bookings', [
            'type' => 'free_demo',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['subject', 'grade', 'starts_at', 'name', 'email']);
    }

    public function test_guest_booking_creates_no_out_of_scope_records(): void
    {
        $response = $this->postJson('/api/v1/guest/bookings', [
            'type' => 'free_demo',
            'subject' => 'maths',
            'grade' => 5,
            'starts_at' => $this->slot()->toIso8601String(),
            'name' => 'Guest Parent',
            'email' => 'guest-scope@example.com',
        ])->assertCreated();

        $booking = Booking::query()->where('reference', $response->json('data.reference'))->firstOrFail();
        $this->assertNull($booking->meeting_provider);
        $this->assertNull($booking->meeting_url);
        $this->assertSame('not_required', $booking->payment_status->value);

        $this->assertSame(0, Wallet::count());
        $this->assertSame(0, WalletLedgerEntry::count());

        foreach (['payments', 'meetings'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Unexpected table [{$table}] found.");
        }
    }

    // ── Student booking ──────────────────────────────────────────────────

    public function test_student_can_request_a_safe_booking(): void
    {
        $this->actingAs($this->student)->postJson('/dashboard/bookings', [
            'type' => 'free_demo',
            'teacher_id' => $this->teacher->id,
            'starts_at' => $this->slot()->toIso8601String(),
            'subject' => 'maths',
            'grade' => 5,
        ])->assertCreated();
    }

    public function test_student_cannot_book_self(): void
    {
        // The student is also an approved instructor of the same subject.
        $dualRole = $this->makeTeacher('maths');
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $dualRole->id])->forDay($day)->between('09:00:00', '11:00:00')->create();
        }

        $this->actingAs($dualRole)->postJson('/dashboard/bookings', [
            'type' => 'free_demo',
            'teacher_id' => $dualRole->id,
            'starts_at' => $this->slot()->toIso8601String(),
            'subject' => 'maths',
            'grade' => 5,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('bookings', ['host_id' => $dualRole->id, 'attendee_id' => $dualRole->id]);
    }

    public function test_student_cannot_access_another_students_booking(): void
    {
        $booking = app(BookingServiceInterface::class)->request(
            $this->bookingData($this->teacher, $this->student, $this->slot()),
        );

        $otherStudent = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->assertTrue($this->student->can('view', $booking));
        $this->assertFalse($otherStudent->can('view', $booking));
    }

    public function test_instructor_can_view_only_assigned_bookings(): void
    {
        $booking = app(BookingServiceInterface::class)->request(
            $this->bookingData($this->teacher, $this->student, $this->slot()),
        );

        $otherTeacher = $this->makeTeacher('science');

        $this->assertTrue($this->teacher->can('view', $booking));
        $this->assertFalse($otherTeacher->can('view', $booking));
    }

    public function test_student_booking_creates_no_out_of_scope_records(): void
    {
        $response = $this->actingAs($this->student)->postJson('/dashboard/bookings', [
            'type' => 'free_demo',
            'teacher_id' => $this->teacher->id,
            'starts_at' => $this->slot()->toIso8601String(),
            'subject' => 'maths',
            'grade' => 5,
        ])->assertCreated();

        $booking = Booking::query()->where('reference', $response->json('data.reference'))->firstOrFail();
        $this->assertNull($booking->meeting_provider);
        $this->assertSame('not_required', $booking->payment_status->value);

        $this->assertSame(0, Wallet::count());
        $this->assertSame(0, WalletLedgerEntry::count());

        foreach (['payments', 'meetings'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Unexpected table [{$table}] found.");
        }
    }

    // ── Admin / permissions ──────────────────────────────────────────────

    public function test_admin_booking_lifecycle_actions_require_permission(): void
    {
        $booking = app(BookingServiceInterface::class)->request(
            $this->bookingData($this->teacher, $this->student, $this->slot()),
        );

        $unpermitted = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $unpermitted->assignRole('manager');

        $this->assertFalse($unpermitted->can('confirm', $booking));
        $this->assertFalse($unpermitted->can('cancel', $booking));
    }

    public function test_non_permitted_admin_cannot_mutate_bookings_via_filament(): void
    {
        $booking = app(BookingServiceInterface::class)->request(
            $this->bookingData($this->teacher, $this->student, $this->slot()),
        );

        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager->assignRole('manager');
        foreach (['ViewAny:Booking', 'View:Booking'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $manager->givePermissionTo(['ViewAny:Booking', 'View:Booking']);

        Livewire::actingAs($manager)
            ->test(ListBookings::class)
            ->assertTableActionHidden('confirm', record: $booking)
            ->assertTableActionHidden('cancel', record: $booking);
    }

    public function test_direct_delete_of_active_booking_is_blocked(): void
    {
        $booking = app(BookingServiceInterface::class)->request(
            $this->bookingData($this->teacher, $this->student, $this->slot()),
        );
        $this->assertSame(BookingStatus::Confirmed, $booking->status);

        $admin = $this->permittedManager();

        // Hidden proactively for a non-terminal booking, not just refused
        // after confirmation — the record must also survive untouched.
        Livewire::actingAs($admin)
            ->test(EditBooking::class, ['record' => $booking->getRouteKey()])
            ->assertActionHidden('delete');

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'deleted_at' => null]);
    }

    public function test_direct_delete_of_terminal_booking_is_allowed(): void
    {
        $booking = app(BookingServiceInterface::class)->request(
            $this->bookingData($this->teacher, $this->student, $this->slot()),
        );
        app(BookingServiceInterface::class)->cancel($booking, new CancelBookingData(BookingActor::Admin, 'test cleanup'));

        $admin = $this->permittedManager();

        Livewire::actingAs($admin)
            ->test(EditBooking::class, ['record' => $booking->getRouteKey()])
            ->callAction('delete');

        $this->assertSoftDeleted('bookings', ['id' => $booking->id]);
    }

    private function permittedManager(): User
    {
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager->assignRole('manager');

        foreach (['ViewAny:Booking', 'View:Booking', 'Update:Booking', 'Delete:Booking'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $manager->givePermissionTo(['ViewAny:Booking', 'View:Booking', 'Update:Booking', 'Delete:Booking']);

        return $manager;
    }
}
