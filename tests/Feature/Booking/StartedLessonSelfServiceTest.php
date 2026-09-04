<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\RescheduleBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Exceptions\LessonAlreadyStartedException;
use App\Livewire\Frontend\Student\BookingHistory;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A lesson whose start time has passed is delivered or missed; the
 * student saw "Reschedule" and "Cancel booking" on a lesson they had
 * already attended because auto-completion runs on a grace delay and the
 * booking stayed Confirmed meanwhile. Self-service closes at the start
 * time regardless of status; admins keep their override.
 */
class StartedLessonSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $this->student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $this->instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
    }

    private function confirmedBooking(CarbonImmutable $startsAt): Booking
    {
        $type = BookingType::factory()->paid(20.00, 'USD')->create(['name' => 'Paid 1-to-1 Session', 'duration_minutes' => 60]);

        return Booking::factory()->paid(20.00, 'USD')->create([
            'booking_type_id' => $type->id,
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Paid,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(60),
        ]);
    }

    public function test_the_detail_modal_offers_no_actions_for_a_lesson_that_has_ended(): void
    {
        $booking = $this->confirmedBooking(CarbonImmutable::now()->subHours(3));

        Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->assertSee('This lesson has ended')
            ->assertDontSee('Cancel booking')
            ->assertDontSeeHtml('wire:click="openReschedulePanel"');
    }

    public function test_the_detail_modal_offers_no_actions_while_a_lesson_is_in_progress(): void
    {
        $booking = $this->confirmedBooking(CarbonImmutable::now()->subMinutes(10));

        Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->assertSee('This lesson is in progress')
            ->assertDontSee('Cancel booking');
    }

    public function test_the_detail_modal_still_offers_actions_before_the_lesson_starts(): void
    {
        $booking = $this->confirmedBooking(CarbonImmutable::now()->addDays(2));

        Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->assertSee('Cancel booking')
            ->assertDontSee('This lesson has ended');
    }

    public function test_opening_a_panel_on_a_started_lesson_explains_instead_of_erroring(): void
    {
        $booking = $this->confirmedBooking(CarbonImmutable::now()->subMinutes(5));

        Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->call('openCancelPanel')
            ->assertSet('cancelPanelOpen', false)
            ->assertSee('can no longer be cancelled');
    }

    public function test_policy_denies_the_student_after_start_but_the_instructor_keeps_the_no_show_path(): void
    {
        $booking = $this->confirmedBooking(CarbonImmutable::now()->subMinutes(5));

        $this->assertFalse(Gate::forUser($this->student)->allows('cancel', $booking));
        $this->assertFalse(Gate::forUser($this->student)->allows('reschedule', $booking));
        // An instructor cancelling after the start is how an instructor
        // no-show becomes a full refund — that must keep working.
        $this->assertTrue(Gate::forUser($this->instructor)->allows('cancel', $booking));

        $upcoming = $this->confirmedBooking(CarbonImmutable::now()->addDay());
        $this->assertTrue(Gate::forUser($this->student)->allows('cancel', $upcoming));
        $this->assertTrue(Gate::forUser($this->student)->allows('reschedule', $upcoming));
    }

    public function test_service_refuses_a_student_reschedule_after_start(): void
    {
        $booking = $this->confirmedBooking(CarbonImmutable::now()->subMinutes(5));

        $this->expectException(LessonAlreadyStartedException::class);

        app(BookingServiceInterface::class)->reschedule($booking, new RescheduleBookingData(
            startsAt: CarbonImmutable::now()->addDays(3)->setTime(10, 0),
            actor: BookingActor::Student,
        ));
    }

    public function test_service_refuses_a_student_cancellation_after_start(): void
    {
        $booking = $this->confirmedBooking(CarbonImmutable::now()->subMinutes(5));

        $this->expectException(LessonAlreadyStartedException::class);

        app(BookingServiceInterface::class)->cancel($booking, new CancelBookingData(BookingActor::Student, 'test'));
    }
}
