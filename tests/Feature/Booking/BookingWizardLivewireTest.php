<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Enums\Weekday;
use App\Livewire\Frontend\Booking\BookingWizard;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The `/book` wizard is authenticated-only end to end — no
 * unauthenticated guest booking concept exists anywhere in this
 * domain. The wizard always attributes the resulting booking to the
 * logged-in student (WizardBookingService::book() uses auth()->id()
 * directly; there is no name/email/phone form step to bypass).
 */
class BookingWizardLivewireTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $teacher->id], [
            'instructor_status' => 'approved',
            'profile_visibility' => 'public',
        ]);
        TeacherSubject::factory()->state(['teacher_id' => $teacher->id])->subject('maths', 1, 12)->create();
        $this->teacher = $teacher;

        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()
                ->state(['teacher_id' => $teacher->id])
                ->forDay($day)
                ->between('09:00:00', '17:00:00')
                ->create();
        }

        BookingType::factory()->create([
            'key' => 'free_demo',
            'name' => 'Free Demo',
            'duration_minutes' => 30,
            'sort_order' => 1,
        ]);

        Livewire::component('frontend.booking.booking-wizard', BookingWizard::class);
    }

    /** An Active student_status is required for booking eligibility — bare role assignment leaves student_status null, which is always denied. */
    private function student(): User
    {
        return User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
    }

    public function test_unauthenticated_visitor_is_redirected_to_login_from_booking_page(): void
    {
        $this->get(route('booking.create'))->assertRedirect(route('auth.login'));
    }

    public function test_authenticated_student_sees_booking_page_renders_livewire_wizard(): void
    {
        $this->actingAs($this->student())
            ->get(route('booking.create'))
            ->assertOk()
            ->assertSeeLivewire(BookingWizard::class)
            ->assertSee('Book a Session');
    }

    public function test_authenticated_student_can_complete_booking_wizard(): void
    {
        $student = $this->student();
        $start = now('UTC')->addDays(3)->setTime(10, 0)->toIso8601String();

        Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'free_demo')
            ->assertSet('step', 2)
            ->call('selectSubject', 'maths')
            ->assertSet('step', 3)
            ->call('selectGrade', 5)
            ->assertSet('step', 4)
            ->call('selectDate', now('UTC')->addDays(3)->toDateString())
            ->assertSet('step', 5)
            ->call('selectSlot', $start)
            ->assertSet('step', 6)
            ->call('submit')
            ->assertSet('step', 7)
            ->assertSee('Booking confirmed');

        $this->assertDatabaseHas('bookings', [
            'student_id' => $student->id,
        ]);
    }

    public function test_instructor_profile_booking_link_locks_booking_to_that_instructor(): void
    {
        $student = $this->student();
        $start = now('UTC')->addDays(3)->setTime(10, 0)->toIso8601String();

        Livewire::actingAs($student)
            ->withQueryParams([
                'instructor' => $this->teacher->slug,
                'type' => 'free_demo',
                'subject' => 'maths',
            ])
            ->test('frontend.booking.booking-wizard')
            ->assertSet('lockedInstructorId', $this->teacher->id)
            ->assertSet('lockedInstructorName', $this->teacher->name)
            ->assertSet('type', 'free_demo')
            ->assertSet('subject', 'maths')
            ->call('selectGrade', 5)
            ->call('selectDate', now('UTC')->addDays(3)->toDateString())
            ->call('selectSlot', $start)
            ->call('submit')
            ->assertSet('step', 7);

        $this->assertDatabaseHas('bookings', [
            'student_id' => $student->id,
            'instructor_id' => $this->teacher->id,
        ]);
    }

    public function test_paid_booking_uses_payment_as_the_final_milestone_until_payment_succeeds(): void
    {
        $paidType = BookingType::factory()->paid()->create([
            'key' => 'paid_one_to_one',
            'name' => 'Paid 1-to-1 Session',
        ]);

        Livewire::actingAs($this->student())
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', $paidType->key)
            ->assertSee('Payment')
            ->assertDontSee('Confirmed')
            ->set('result', ['requires_payment' => false])
            ->assertSee('Confirmed');
    }

    public function test_guest_cannot_complete_booking_wizard_even_if_component_is_reached_directly(): void
    {
        // Defense-in-depth: even bypassing the route's 'auth' middleware
        // (e.g. testing the Livewire component in isolation), the service
        // layer itself refuses an unauthenticated submission — caught by
        // the component's own BookingException handling (same as every
        // other domain rejection) and surfaced as a banner, never a raw
        // exception to the visitor — and never creates a booking draft.
        $start = now('UTC')->addDays(3)->setTime(10, 0)->toIso8601String();

        Livewire::test('frontend.booking.booking-wizard')
            ->call('selectMode', 'free_demo')
            ->call('selectSubject', 'maths')
            ->call('selectGrade', 5)
            ->call('selectDate', now('UTC')->addDays(3)->toDateString())
            ->call('selectSlot', $start)
            ->call('submit')
            ->assertSet('step', 6)
            ->assertSet('banner', 'Please log in or create an account to book a lesson.');

        $this->assertDatabaseCount('bookings', 0);
    }
}
