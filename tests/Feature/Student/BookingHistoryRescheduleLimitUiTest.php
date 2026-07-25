<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Livewire\Frontend\Student\BookingHistory;
use App\Models\Booking;
use App\Models\BookingActivity;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\BookingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24D — the student-facing remaining-reschedule-allowance display
 * and exhausted state on the booking-history modal. The UI is never the
 * authority: every scenario here also exercises the real
 * BookingService::reschedule() path so a stale render cannot bypass the
 * limit.
 */
class BookingHistoryRescheduleLimitUiTest extends TestCase
{
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
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)->between('06:00:00', '22:00:00')->create();
        }
    }

    private function setLimit(int $limit): void
    {
        $settings = app(BookingSettings::class);
        $settings->reschedule_limit = $limit;
        $settings->save();
    }

    private function bookingAt(CarbonImmutable $startsAt): Booking
    {
        $type = BookingType::factory()->create(['requires_approval' => false]);

        return Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->teacher->id,
            'booking_type_id' => $type->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::NotRequired,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
        ]);
    }

    public function test_reschedule_panel_shows_remaining_allowance(): void
    {
        $this->setLimit(2);
        $booking = $this->bookingAt(CarbonImmutable::now()->addDays(1)->setTime(10, 0));

        Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->call('openReschedulePanel')
            ->assertSee('2 reschedules remaining');
    }

    public function test_reschedule_panel_shows_singular_wording_for_one_remaining(): void
    {
        $this->setLimit(1);
        $booking = $this->bookingAt(CarbonImmutable::now()->addDays(1)->setTime(10, 0));

        Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->call('openReschedulePanel')
            ->assertSee('1 reschedule remaining')
            ->assertDontSee('1 reschedules remaining');
    }

    public function test_reschedule_button_is_hidden_and_limit_message_shown_once_exhausted(): void
    {
        $this->setLimit(1);
        $booking = $this->bookingAt(CarbonImmutable::now()->addDays(1)->setTime(10, 0));

        BookingActivity::factory()->create([
            'booking_id' => $booking->id,
            'action' => BookingActivityAction::Rescheduled,
            'actor_type' => BookingActor::Student,
        ]);

        Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->assertSee('You have reached the reschedule limit for this lesson.')
            ->assertDontSee('wire:click="openReschedulePanel"', false);
    }

    public function test_stale_page_cannot_bypass_a_since_reduced_limit_on_submission(): void
    {
        $this->setLimit(2);
        $booking = $this->bookingAt(CarbonImmutable::now()->addDays(1)->setTime(10, 0));

        BookingActivity::factory()->create([
            'booking_id' => $booking->id,
            'action' => BookingActivityAction::Rescheduled,
            'actor_type' => BookingActor::Student,
        ]);

        $test = Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->call('openReschedulePanel')
            ->assertSee('1 reschedule remaining');

        // The limit is lowered server-side after the panel already
        // rendered "1 remaining" — the stale component must still be
        // rejected authoritatively on submission.
        $this->setLimit(1);

        $newDate = CarbonImmutable::now()->addDays(2);

        $test->set('rescheduleDate', $newDate->toDateString())
            ->call('selectRescheduleSlot', $newDate->setTime(10, 0)->toIso8601String())
            ->call('confirmReschedule')
            ->assertSet('modalBanner', 'You have reached the reschedule limit for this lesson.');

        $this->assertSame(
            1,
            BookingActivity::query()->where('booking_id', $booking->id)->where('action', BookingActivityAction::Rescheduled)->count(),
            'The rejected submission must not have written a second Rescheduled activity row.',
        );
    }

    public function test_successful_reschedule_updates_the_displayed_remaining_allowance(): void
    {
        $this->setLimit(2);
        $booking = $this->bookingAt(CarbonImmutable::now()->addDays(1)->setTime(10, 0));

        $newDate = CarbonImmutable::now()->addDays(2);

        Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->call('openReschedulePanel')
            ->assertSee('2 reschedules remaining')
            ->set('rescheduleDate', $newDate->toDateString())
            ->call('selectRescheduleSlot', $newDate->setTime(10, 0)->toIso8601String())
            ->call('confirmReschedule')
            ->call('openReschedulePanel')
            ->assertSee('1 reschedule remaining');
    }
}
