<?php

declare(strict_types=1);

namespace Tests\Feature\Guest;

use App\Booking\Enums\Weekday;
use App\Livewire\Frontend\Booking\BookingWizard;
use App\Models\Booking;
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
 * Phase 10.2C-Fix: no guest booking — the wizard at /book and
 * /instructors/book now requires an authenticated student (route
 * middleware) and always attributes the resulting booking to that
 * account (BookingWizardService::book() already passed auth()->id()
 * as attendeeId; AuthenticatedAttendeeRule now rejects a null one
 * instead of silently creating a guest-shaped booking).
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

        $otherTeacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $otherTeacher->id], [
            'instructor_status' => 'approved',
            'profile_visibility' => 'public',
        ]);
        TeacherSubject::factory()->state(['teacher_id' => $otherTeacher->id])->subject('maths', 1, 12)->create();

        foreach (Weekday::cases() as $day) {
            foreach ([$teacher, $otherTeacher] as $availableTeacher) {
                TeacherAvailability::factory()
                    ->state(['teacher_id' => $availableTeacher->id])
                    ->forDay($day)
                    ->between('09:00:00', '17:00:00')
                    ->create();
            }
        }

        BookingType::factory()->create([
            'key' => 'free_demo',
            'name' => 'Free Demo',
            'duration_minutes' => 30,
            'max_attendees' => 1,
            'sort_order' => 1,
        ]);

        Livewire::component('frontend.booking.booking-wizard', BookingWizard::class);
    }

    private function student(): User
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        return $student;
    }

    public function test_unauthenticated_visitor_is_redirected_to_login_from_booking_page(): void
    {
        $this->get(route('booking.create'))->assertRedirect(route('auth.login'));
    }

    public function test_unauthenticated_visitor_is_redirected_to_login_from_instructor_booking_alias(): void
    {
        $this->get(route('instructors.booking.create'))->assertRedirect(route('auth.login'));
    }

    public function test_authenticated_student_sees_booking_page_renders_livewire_wizard(): void
    {
        $this->actingAs($this->student())
            ->get(route('booking.create'))
            ->assertOk()
            ->assertSeeLivewire(BookingWizard::class)
            ->assertSee('Book a Session');
    }

    public function test_authenticated_student_sees_instructor_booking_alias_renders_livewire_wizard(): void
    {
        $this->actingAs($this->student())
            ->get(route('instructors.booking.create'))
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
            ->call('selectSubject', 'maths')
            ->assertSet('step', 2)
            ->call('selectGrade', 5)
            ->assertSet('step', 3)
            ->call('selectDate', now('UTC')->addDays(3)->toDateString())
            ->assertSet('step', 4)
            ->call('selectSlot', $start)
            ->assertSet('step', 5)
            ->set('name', $student->name)
            ->set('email', $student->email)
            ->call('review')
            ->assertSet('step', 6)
            ->call('submit')
            ->assertSet('step', 7)
            ->assertSee('Booking confirmed');

        $this->assertDatabaseHas('bookings', [
            'attendee_id' => $student->id,
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
            ->assertSet('subject', 'maths')
            ->call('selectGrade', 5)
            ->call('selectDate', now('UTC')->addDays(3)->toDateString())
            ->call('selectSlot', $start)
            ->set('name', $student->name)
            ->set('email', $student->email)
            ->call('review')
            ->call('submit')
            ->assertSet('step', 7);

        $this->assertDatabaseHas('bookings', [
            'attendee_id' => $student->id,
            'host_id' => $this->teacher->id,
        ]);
    }

    public function test_guest_cannot_complete_booking_wizard_even_if_component_is_reached_directly(): void
    {
        // Defense-in-depth: even bypassing the route's 'auth' middleware
        // (e.g. testing the Livewire component in isolation), the service
        // layer itself refuses an unauthenticated submission.
        $start = now('UTC')->addDays(3)->setTime(10, 0)->toIso8601String();

        Livewire::test('frontend.booking.booking-wizard')
            ->call('selectSubject', 'maths')
            ->call('selectGrade', 5)
            ->call('selectDate', now('UTC')->addDays(3)->toDateString())
            ->call('selectSlot', $start)
            ->set('name', 'Guest Parent')
            ->set('email', 'parent@example.com')
            ->call('review')
            ->call('submit')
            ->assertSet('step', 6)
            ->assertSee('Please log in or create an account');

        $this->assertDatabaseMissing('bookings', ['guest_email' => 'parent@example.com']);
    }
}
