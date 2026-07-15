<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

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
}
