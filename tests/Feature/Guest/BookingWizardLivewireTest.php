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
use Tests\TestCase;

class BookingWizardLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $teacher->id])->subject('maths', 1, 12)->create();

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
            'max_attendees' => 1,
            'sort_order' => 1,
        ]);

        Livewire::component('frontend.booking.booking-wizard', BookingWizard::class);
    }

    public function test_booking_page_renders_livewire_wizard(): void
    {
        $this->get(route('booking.create'))
            ->assertOk()
            ->assertSeeLivewire(BookingWizard::class)
            ->assertSee('Book a Session');
    }

    public function test_guest_can_complete_booking_wizard(): void
    {
        $start = now('UTC')->addDays(3)->setTime(10, 0)->toIso8601String();

        Livewire::test('frontend.booking.booking-wizard')
            ->call('selectSubject', 'maths')
            ->assertSet('step', 2)
            ->call('selectGrade', 5)
            ->assertSet('step', 3)
            ->call('selectDate', now('UTC')->addDays(3)->toDateString())
            ->assertSet('step', 4)
            ->call('selectSlot', $start)
            ->assertSet('step', 5)
            ->set('name', 'Guest Parent')
            ->set('email', 'parent@example.com')
            ->call('review')
            ->assertSet('step', 6)
            ->call('submit')
            ->assertSet('step', 7)
            ->assertSee('Booking confirmed')
            ->assertSee('Manage code');

        $this->assertDatabaseHas('bookings', [
            'guest_email' => 'parent@example.com',
        ]);

        $this->assertNotNull(Booking::query()->where('guest_email', 'parent@example.com')->value('manage_token'));
    }
}
