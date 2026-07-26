<?php

declare(strict_types=1);

namespace Tests\Feature\Booking\Concurrency;

use App\Booking\Enums\Weekday;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;

/**
 * Real multi-process race for SRS 11.13/11.39 ("one free demo per
 * instructor"): two genuinely separate processes attempt to
 * create a free-demo booking for the SAME student+instructor pair at
 * the same instant, at two different time slots (so the pre-existing
 * duplicate-slot guard is never what decides the outcome — only the
 * one-demo-per-instructor rule is under test here). Reuses the
 * tests/Concurrency/run-op.php harness proven throughout the booking
 * and financial domains.
 */
class FreeDemoConcurrencyTest extends ConcurrencyTestCase
{
    public function test_concurrent_free_demo_requests_for_the_same_pair_resolve_exactly_once(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

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

        BookingType::factory()->create(['key' => 'free_demo', 'duration_minutes' => 30]);

        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);

        $slotA = CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0)->toIso8601String();
        $slotB = CarbonImmutable::now('UTC')->addDays(4)->setTime(11, 0)->toIso8601String();

        $results = $this->race([
            ['book-free-demo', ['student_id' => $student->id, 'instructor_id' => $teacher->id, 'starts_at' => $slotA]],
            ['book-free-demo', ['student_id' => $student->id, 'instructor_id' => $teacher->id, 'starts_at' => $slotB]],
        ]);

        $succeeded = array_values(array_filter($results, fn (array $r): bool => $r['ok']));
        $failed = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));

        // Exactly one request must win the free demo; the loser must be
        // rejected specifically by the free-demo rule, not by some other
        // incidental failure.
        $this->assertCount(1, $succeeded, json_encode($results));
        $this->assertCount(1, $failed, json_encode($results));
        $this->assertSame('App\Booking\Exceptions\FreeDemoAlreadyUsedException', $failed[0]['exception']);

        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseHas('bookings', [
            'student_id' => $student->id,
            'instructor_id' => $teacher->id,
        ]);
    }
}
