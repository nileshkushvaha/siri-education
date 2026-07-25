<?php

declare(strict_types=1);

namespace Tests\Feature\Booking\Concurrency;

use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Models\Booking;
use App\Models\BookingActivity;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\BookingSettings;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;

/**
 * Phase 24D — real multi-process race proving that when only one
 * reschedule allowance remains, exactly one of two concurrent
 * reschedule requests for the SAME booking succeeds and exactly one
 * new Rescheduled activity row is ever written — never two, even
 * though both requests read the "allowed" decision from the same
 * starting state. Mirrors the harness pattern proven in
 * BookingRefundConcurrencyTest / CancellationRefundDispositionConcurrencyTest.
 */
class RescheduleLimitConcurrencyTest extends ConcurrencyTestCase
{
    public function test_concurrent_reschedules_for_the_final_allowance_resolve_to_exactly_one_success(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $settings = app(BookingSettings::class);
        $settings->reschedule_limit = 1;
        $settings->save();

        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $teacher->id], ['instructor_status' => 'approved']);
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $teacher->id])
                ->forDay($day)->between('06:00:00', '22:00:00')->create();
        }

        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $type = BookingType::factory()->create(['requires_approval' => false]);
        $startsAt = CarbonImmutable::now()->addDays(1)->setTime(10, 0);

        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $teacher->id,
            'booking_type_id' => $type->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::NotRequired,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
        ]);

        $targetA = CarbonImmutable::now()->addDays(2)->setTime(10, 0);
        $targetB = CarbonImmutable::now()->addDays(3)->setTime(10, 0);

        $results = $this->race([
            ['reschedule-booking', ['booking_id' => $booking->id, 'actor' => 'student', 'starts_at' => $targetA->toIso8601String()]],
            ['reschedule-booking', ['booking_id' => $booking->id, 'actor' => 'student', 'starts_at' => $targetB->toIso8601String()]],
        ]);

        $succeeded = array_values(array_filter($results, fn (array $r): bool => $r['ok']));
        $failed = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));

        $this->assertCount(1, $succeeded, json_encode($results));
        $this->assertCount(1, $failed, json_encode($results));
        $this->assertSame('App\Booking\Exceptions\RescheduleLimitReachedException', $failed[0]['exception']);

        $this->assertSame(
            1,
            BookingActivity::query()->where('booking_id', $booking->id)->where('action', BookingActivityAction::Rescheduled)->count(),
            'Exactly one Rescheduled activity row must exist — never two, never zero.',
        );

        $booking->refresh();
        $this->assertTrue($booking->starts_at->equalTo($targetA) || $booking->starts_at->equalTo($targetB));
    }
}
