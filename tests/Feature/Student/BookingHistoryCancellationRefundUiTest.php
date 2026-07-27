<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Livewire\Frontend\Student\BookingHistory;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\BookingType;
use App\Models\Currency;
use App\Models\User;
use App\Settings\BookingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The student-facing preview (before confirming a
 * cancellation) and the frozen post-cancellation outcome message on the
 * booking-history modal.
 */
class BookingHistoryCancellationRefundUiTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $this->student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
    }

    private function setWindow(int $hours): void
    {
        $settings = app(BookingSettings::class);
        $settings->cancellation_window_hours = $hours;
        $settings->save();
    }

    private function paidBooking(CarbonImmutable $startsAt): Booking
    {
        $type = BookingType::factory()->create(['is_paid' => true]);
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $booking = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $teacher->id,
            'booking_type_id' => $type->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Paid,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'price' => '499.00',
            'currency' => 'INR',
            'payment_reference' => 'PAY-UI-TEST-'.$startsAt->timestamp,
        ]);

        BookingPayment::query()->create([
            'booking_id' => $booking->id,
            'user_id' => $this->student->id,
            'provider' => 'fake',
            'amount_minor' => 49900,
            'currency_code' => 'INR',
            'status' => 'captured',
            'idempotency_key' => (string) $booking->payment_reference,
        ]);

        return $booking;
    }

    public function test_cancel_panel_shows_eligible_refund_preview_before_the_cutoff(): void
    {
        $this->setWindow(24);
        $booking = $this->paidBooking(CarbonImmutable::now()->addDays(3));

        Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->call('openCancelPanel')
            ->assertSee('Eligible for a full wallet refund.')
            ->assertDontSee('outside the refund window');
    }

    public function test_cancel_panel_shows_ineligible_refund_preview_inside_the_window(): void
    {
        $this->setWindow(24);
        $booking = $this->paidBooking(CarbonImmutable::now()->addHours(2));

        Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->call('openCancelPanel')
            ->assertSee('outside the refund window')
            ->assertSee('refund deadline was')
            ->assertDontSee('Eligible for a full wallet refund.');
    }

    public function test_confirming_an_eligible_cancellation_shows_the_frozen_wallet_credited_outcome(): void
    {
        $this->setWindow(24);
        $booking = $this->paidBooking(CarbonImmutable::now()->addDays(3));

        Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->call('openCancelPanel')
            ->call('confirmCancel')
            ->assertSee('The amount paid has been credited to your wallet.');
    }

    public function test_confirming_a_late_cancellation_shows_the_frozen_no_refund_outcome(): void
    {
        $this->setWindow(24);
        $booking = $this->paidBooking(CarbonImmutable::now()->addHours(2));

        Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->call('openCancelPanel')
            ->call('confirmCancel')
            ->assertSee('This cancellation was outside the refund window, so no refund was issued.');
    }

    public function test_changing_the_window_setting_after_cancellation_does_not_alter_the_displayed_outcome(): void
    {
        $this->setWindow(24);
        $booking = $this->paidBooking(CarbonImmutable::now()->addHours(2));

        $test = Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->call('openCancelPanel')
            ->call('confirmCancel')
            ->assertSee('This cancellation was outside the refund window, so no refund was issued.');

        $this->setWindow(0);

        $test->call('viewBooking', $booking->id)
            ->assertSee('This cancellation was outside the refund window, so no refund was issued.');
    }

    public function test_free_demo_cancellation_shows_no_refund_preview_or_outcome_section(): void
    {
        $demoType = BookingType::factory()->create(['key' => 'free_demo', 'is_paid' => false]);
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $startsAt = CarbonImmutable::now()->addDays(3);

        $booking = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $teacher->id,
            'booking_type_id' => $demoType->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::NotRequired,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
        ]);

        Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->call('openCancelPanel')
            ->assertDontSee('Eligible for a full wallet refund.')
            ->assertDontSee('outside the refund window')
            ->call('confirmCancel')
            ->assertDontSee('credited to your wallet')
            ->assertDontSee('This cancellation was outside the refund window');
    }
}
