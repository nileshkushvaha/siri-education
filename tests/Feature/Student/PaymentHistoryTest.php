<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Livewire\Frontend\Student\BookingHistory;
use App\Livewire\Frontend\Student\PaymentHistory;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');
    }

    public function test_page_renders_the_livewire_component(): void
    {
        $this->actingAs($this->student)
            ->get(route('dashboard.payments'))
            ->assertOk()
            ->assertSeeLivewire(PaymentHistory::class);
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(route('dashboard.payments'))->assertRedirect(route('auth.login'));
    }

    public function test_shows_paid_bookings_with_price(): void
    {
        $type = BookingType::factory()->paid(49.99, 'USD')->create(['name' => 'Paid Session']);
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        Booking::factory()->paid(49.99, 'USD')->create([
            'booking_type_id' => $type->id,
            'student_id' => $this->student->id,
            'instructor_id' => $teacher->id,
        ]);

        Livewire::actingAs($this->student)
            ->test(PaymentHistory::class)
            ->assertSee('Paid Session')
            ->assertSee('49.99');
    }

    public function test_excludes_bookings_that_do_not_require_payment(): void
    {
        $type = BookingType::factory()->create(['name' => 'Free Demo']);
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        Booking::factory()->create([
            'booking_type_id' => $type->id,
            'student_id' => $this->student->id,
            'instructor_id' => $teacher->id,
        ]);

        Livewire::actingAs($this->student)
            ->test(PaymentHistory::class)
            ->assertSee('No payments yet')
            ->assertDontSee('Free Demo');
    }

    private function pendingBooking(array $attributes = []): Booking
    {
        $type = BookingType::factory()->paid(20.00, 'AUD')->create(['name' => 'Paid 1-to-1 Session']);
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        return Booking::factory()->paid(20.00, 'AUD')->create([
            'booking_type_id' => $type->id,
            'student_id' => $this->student->id,
            'instructor_id' => $teacher->id,
            'status' => BookingStatus::Pending,
            'payment_status' => BookingPaymentStatus::Pending,
            'payment_reference' => 'PAY-TEST1234',
            ...$attributes,
        ]);
    }

    /** A live booking awaiting payment gets a way to act, not just a badge. */
    public function test_pending_payment_on_an_active_booking_offers_to_complete_payment(): void
    {
        $booking = $this->pendingBooking();

        Livewire::actingAs($this->student)
            ->test(PaymentHistory::class)
            ->assertSee('Complete payment')
            ->assertSeeHtml(route('dashboard.my-bookings', ['booking' => $booking->id]));
    }

    public function test_failed_payment_on_an_active_booking_offers_to_complete_payment(): void
    {
        $this->pendingBooking(['payment_status' => BookingPaymentStatus::Failed]);

        Livewire::actingAs($this->student)
            ->test(PaymentHistory::class)
            ->assertSee('Payment Failed')
            ->assertSee('Complete payment');
    }

    /** A cancelled booking must never invite a payment. */
    public function test_pending_payment_on_a_cancelled_booking_offers_no_payment_link(): void
    {
        $this->pendingBooking(['status' => BookingStatus::Cancelled]);

        Livewire::actingAs($this->student)
            ->test(PaymentHistory::class)
            ->assertDontSee('Complete payment');
    }

    /** No gateway reference means checkout never began — say so instead of "pending". */
    public function test_pending_without_a_gateway_reference_reads_as_not_started(): void
    {
        $this->pendingBooking(['payment_reference' => null]);

        Livewire::actingAs($this->student)
            ->test(PaymentHistory::class)
            ->assertSee('Payment not started')
            ->assertDontSee('Payment Pending');
    }

    /** The deep link opens that booking's detail modal, where the existing Pay now flow lives. */
    public function test_my_bookings_deep_link_opens_the_booking_detail(): void
    {
        $booking = $this->pendingBooking();

        Livewire::actingAs($this->student)
            ->withQueryParams(['booking' => $booking->id])
            ->test(BookingHistory::class)
            ->assertSet('selectedBooking.id', $booking->id)
            ->assertDispatched('open-modal', id: 'booking-detail-modal')
            ->assertSee('Pay now');
    }

    /** Another student's id in the link is ignored, not an error page. */
    public function test_my_bookings_deep_link_ignores_a_booking_that_is_not_the_students(): void
    {
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $other->assignRole('student');
        $booking = $this->pendingBooking(['student_id' => $other->id]);

        Livewire::actingAs($this->student)
            ->withQueryParams(['booking' => $booking->id])
            ->test(BookingHistory::class)
            ->assertOk()
            ->assertSet('selectedBooking', null);
    }
}
