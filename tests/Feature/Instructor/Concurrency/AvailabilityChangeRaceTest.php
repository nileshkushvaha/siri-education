<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor\Concurrency;

use App\Booking\Enums\Weekday;
use App\Enums\InstructorStatus;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;

/**
 * TRUE cross-process race between a free-demo
 * booking and an unconfirmed availability deactivation for the same
 * instructor. Both operations serialize on the same booking:host:%d
 * GET_LOCK, so exactly one order occurs: booking-first → the
 * deactivation observes the fresh confirmed booking and is rejected
 * with requires-confirmation (window stays active); deactivation-first
 * → the booking's in-lock ensureAvailable() finds no covering window
 * and fails (deactivation stands). Never both succeeding; never a
 * window deactivated with an unacknowledged affected booking.
 */
class AvailabilityChangeRaceTest extends ConcurrencyTestCase
{
    public function test_booking_versus_availability_deactivation_produces_a_valid_serialized_outcome(): void
    {
        [$teacher, $student, $window] = $this->fixtures();

        $slot = CarbonImmutable::now('UTC')->addDays(7)->next(CarbonImmutable::MONDAY)->setTime(10, 0);

        $results = $this->race([
            ['book-free-demo', ['student_id' => $student->id, 'instructor_id' => $teacher->id, 'starts_at' => $slot->toIso8601String()]],
            ['availability-deactivate', ['actor_id' => $teacher->id, 'availability_id' => $window->id]],
        ]);

        $booking = collect($results)->firstWhere('op', 'book-free-demo');
        $deactivation = collect($results)->firstWhere('op', 'availability-deactivate');

        if ($booking['ok']) {
            // Booking committed first: the deactivation must have been
            // rejected pending confirmation, and the window stays active.
            $this->assertFalse($deactivation['ok'], 'Both operations must never succeed: '.json_encode($results));
            $this->assertStringContainsString('confirmed upcoming lesson', $deactivation['message']);
            $this->assertTrue($window->fresh()->is_active);
            $this->assertSame(1, Booking::query()->count());
        } else {
            // Deactivation committed first: the booking must have failed
            // against the reduced schedule, and no booking row exists.
            $this->assertTrue($deactivation['ok'], 'At least one operation must succeed: '.json_encode($results));
            $this->assertFalse($window->fresh()->is_active);
            $this->assertSame(0, Booking::query()->count());
        }
    }

    /** @return array{0: User, 1: User, 2: TeacherAvailability} */
    private function fixtures(): array
    {
        BookingType::factory()->create(['key' => 'free_demo', 'duration_minutes' => 30]);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $teacher->assignRole('instructor');
        UserProfile::query()->updateOrCreate(
            ['user_id' => $teacher->id],
            ['instructor_status' => InstructorStatus::Approved, 'profile_visibility' => 'public', 'timezone' => 'UTC'],
        );
        TeacherSubject::factory()->state(['teacher_id' => $teacher->id])->subject('maths', 1, 12)->create();

        $window = TeacherAvailability::query()->create([
            'teacher_id' => $teacher->id,
            'day_of_week' => Weekday::Monday,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'timezone' => 'UTC',
            'is_active' => true,
            'created_by' => $teacher->id,
            'updated_by' => $teacher->id,
        ]);

        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);

        return [$teacher, $student, $window];
    }
}
