<?php

declare(strict_types=1);

namespace Tests\Feature\Booking\Concurrency;

use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\Weekday;
use App\Models\BookingPayment;
use App\Models\Currency;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;

/**
 * A real cross-process race between an admin disabling a currency and
 * a student initiating a new payment
 * attempt in that same currency. Both sides lock the same Currency row
 * inside a DB transaction, so exactly one valid serial outcome occurs:
 * never both succeeding, never a new payment attempt created after (or
 * concurrently with) the currency being committed inactive.
 */
class CurrencyDeactivationConcurrencyTest extends ConcurrencyTestCase
{
    use CreatesStudentLessonPrices;

    public function test_disable_versus_payment_attempt_produces_exactly_one_valid_serial_outcome(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $student->profile()->update(['phone_e164' => '+919999900001', 'phone_verified_at' => now()]);

        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR');
        $this->assignBillingCountry($student, $priced['country']);

        $booking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $student->id,
            teacherId: $teacher->id,
            startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ))->refresh();

        $results = $this->race([
            ['disable-currency', ['currency_id' => $priced['currency']->id]],
            ['fake-initiate', ['booking_id' => $booking->id]],
        ]);

        $disable = collect($results)->firstWhere('op', 'disable-currency');
        $initiate = collect($results)->firstWhere('op', 'fake-initiate');

        $this->assertTrue($disable['ok'], json_encode($results));

        if ($initiate['ok']) {
            // initiate-first: the currency was still Active when this
            // transaction's lock committed — the attempt stands, and
            // the disable (which committed afterward) does not undo it.
            $this->assertSame(1, BookingPayment::query()->where('booking_id', $booking->id)->count());
        } else {
            // disable-first: rejected with the safe generic message,
            // never a provider call, no payment row created.
            $this->assertStringContainsString('currently unavailable for new payments', $initiate['message']);
            $this->assertSame(0, BookingPayment::query()->where('booking_id', $booking->id)->count());
        }

        $this->assertSame('inactive', Currency::query()->findOrFail($priced['currency']->id)->status);
    }
}
