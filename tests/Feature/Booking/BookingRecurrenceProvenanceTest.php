<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\Contracts\WizardBookingServiceInterface;
use App\Booking\DTOs\RecurrenceData;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\DTOs\WizardBookingData;
use App\Booking\Enums\RecurrenceFrequency;
use App\Booking\Enums\Weekday;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * Phase 18C data-provenance decision (Outcome B, §6.1): the recurring-
 * booking workflow already knew the recurrence frequency at creation
 * time but never persisted it. These tests prove the new
 * `bookings.recurrence_frequency` column is populated correctly by
 * BOTH independent recurring-booking creation paths
 * (`WizardBookingService`/`StudentBookingService`), left `null` for
 * every single/non-recurring booking, and never backfilled for
 * existing rows.
 */
class BookingRecurrenceProvenanceTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->teacher->id], [
            'instructor_status' => 'approved',
            'profile_visibility' => 'public',
        ]);
        TeacherSubject::factory()->state(['teacher_id' => $this->teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        BookingType::query()->firstOrCreate(['key' => 'free_demo'], ['name' => 'Free Demo', 'duration_minutes' => 30, 'is_active' => true]);
    }

    private function student(): User
    {
        return User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
    }

    private function paidTypeWithPrice(): array
    {
        return $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR', durationMinutes: 60);
    }

    // ── WizardBookingService ──────────────────────────────────────────────

    public function test_wizard_recurring_weekly_persists_frequency_on_every_occurrence(): void
    {
        $priced = $this->paidTypeWithPrice();
        $student = $this->student();
        $this->assignBillingCountry($student, $priced['country']);
        $this->actingAs($student);

        app(WizardBookingServiceInterface::class)->bookRecurring(
            new WizardBookingData(
                typeKey: 'paid_one_to_one',
                subject: 'maths',
                grade: 5,
                startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
                timezone: 'UTC',
                teacherId: $this->teacher->id,
            ),
            new RecurrenceData(3, RecurrenceFrequency::Weekly),
        );

        $frequencies = Booking::query()->pluck('recurrence_frequency')->unique();
        $this->assertCount(3, Booking::query()->get());
        $this->assertSame([RecurrenceFrequency::Weekly], $frequencies->all());
    }

    public function test_wizard_recurring_daily_persists_frequency_on_every_occurrence(): void
    {
        $priced = $this->paidTypeWithPrice();
        $student = $this->student();
        $this->assignBillingCountry($student, $priced['country']);
        $this->actingAs($student);

        app(WizardBookingServiceInterface::class)->bookRecurring(
            new WizardBookingData(
                typeKey: 'paid_one_to_one',
                subject: 'maths',
                grade: 5,
                startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
                timezone: 'UTC',
                teacherId: $this->teacher->id,
            ),
            new RecurrenceData(3, RecurrenceFrequency::Daily),
        );

        $frequencies = Booking::query()->pluck('recurrence_frequency')->unique();
        $this->assertSame([RecurrenceFrequency::Daily], $frequencies->all());
    }

    public function test_wizard_single_booking_leaves_frequency_null(): void
    {
        $priced = $this->paidTypeWithPrice();
        $student = $this->student();
        $this->assignBillingCountry($student, $priced['country']);
        $this->actingAs($student);

        app(WizardBookingServiceInterface::class)->book(new WizardBookingData(
            typeKey: 'paid_one_to_one',
            subject: 'maths',
            grade: 5,
            startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
            timezone: 'UTC',
            teacherId: $this->teacher->id,
        ));

        $booking = Booking::query()->firstOrFail();
        $this->assertNull($booking->recurrence_frequency);
    }

    // ── StudentBookingService ─────────────────────────────────────────────

    public function test_student_booking_service_recurring_weekly_persists_frequency(): void
    {
        $priced = $this->paidTypeWithPrice();
        $student = $this->student();
        $this->assignBillingCountry($student, $priced['country']);

        app(StudentBookingServiceInterface::class)->bookRecurring(
            new StudentBookingData(
                typeKey: 'paid_one_to_one',
                studentId: $student->id,
                teacherId: $this->teacher->id,
                startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
                subject: 'maths',
                grade: 5,
            ),
            new RecurrenceData(3, RecurrenceFrequency::Weekly),
        );

        $frequencies = Booking::query()->where('student_id', $student->id)->pluck('recurrence_frequency')->unique();
        $this->assertSame([RecurrenceFrequency::Weekly], $frequencies->all());
    }

    public function test_student_booking_service_recurring_daily_persists_frequency(): void
    {
        $priced = $this->paidTypeWithPrice();
        $student = $this->student();
        $this->assignBillingCountry($student, $priced['country']);

        app(StudentBookingServiceInterface::class)->bookRecurring(
            new StudentBookingData(
                typeKey: 'paid_one_to_one',
                studentId: $student->id,
                teacherId: $this->teacher->id,
                startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
                subject: 'maths',
                grade: 5,
            ),
            new RecurrenceData(3, RecurrenceFrequency::Daily),
        );

        $frequencies = Booking::query()->where('student_id', $student->id)->pluck('recurrence_frequency')->unique();
        $this->assertSame([RecurrenceFrequency::Daily], $frequencies->all());
    }

    public function test_student_booking_service_single_booking_leaves_frequency_null(): void
    {
        $priced = $this->paidTypeWithPrice();
        $student = $this->student();
        $this->assignBillingCountry($student, $priced['country']);

        app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
            subject: 'maths',
            grade: 5,
        ));

        $booking = Booking::query()->where('student_id', $student->id)->firstOrFail();
        $this->assertNull($booking->recurrence_frequency);
    }

    // ── Historical rows are never backfilled ──────────────────────────────

    public function test_a_booking_created_directly_without_the_column_set_remains_null_and_is_not_backfilled(): void
    {
        $priced = $this->paidTypeWithPrice();
        $student = $this->student();

        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => $priced['type']->id,
            'instructor_id' => $this->teacher->id,
            'student_id' => $student->id,
            'meta' => ['recurring_group' => (string) Str::uuid()], // historical marker, pre-dates the column
        ]);

        $this->assertNull($booking->fresh()->recurrence_frequency);
    }
}
