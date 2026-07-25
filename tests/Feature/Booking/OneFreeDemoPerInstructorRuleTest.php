<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\FreeDemoAlreadyUsedException;
use App\Livewire\Frontend\Booking\BookingWizard;
use App\Models\AcademicCategory;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\StudentLessonPrice;
use App\Models\Subject;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24A — SRS 11.13/11.39: a student may book only one free demo
 * per instructor. Covers the domain rule (OneFreeDemoPerInstructorRule),
 * its concurrency-safe re-check in BookingService::request(), and the
 * historical-status interpretation documented in the phase report
 * (only a demo cancelled before ever being confirmed fails to consume
 * the allowance — everything that reached Confirmed at least once,
 * including a later cancellation, no-show, or soft-deleted/archived
 * row, consumes it).
 */
class OneFreeDemoPerInstructorRuleTest extends TestCase
{
    use RefreshDatabase;

    private BookingType $demoType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->demoType = BookingType::factory()->create(['key' => 'free_demo', 'duration_minutes' => 30]);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
    }

    private function makeTeacher(string $subject = 'maths'): User
    {
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $teacher->id])->subject($subject, 1, 12)->create();

        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()
                ->state(['teacher_id' => $teacher->id])
                ->forDay($day)
                ->between('09:00:00', '17:00:00')
                ->create();
        }

        return $teacher;
    }

    /** Phase 24H.1A: an Active student_status is required for booking eligibility now — bare role assignment left student_status null, which is always denied. */
    private function makeStudent(): User
    {
        return User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
    }

    /** Distinct slots per $daysAhead keep tests isolated from the (separate) same-slot duplicate-booking rule. */
    private function slot(int $daysAhead): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->addDays($daysAhead)->setTime(10, 0);
    }

    private function demoData(User $student, User $instructor, int $daysAhead): CreateBookingData
    {
        return new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $student->id,
            instructorId: $instructor->id,
            startsAt: $this->slot($daysAhead),
            durationMinutes: 30,
        );
    }

    /** Inserted directly (not via the service) to control historical status/confirmed_at precisely. */
    private function historicalDemo(User $student, User $instructor, array $attributes): Booking
    {
        return Booking::factory()->for($this->demoType, 'type')->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'starts_at' => $this->slot(-10),
            'ends_at' => $this->slot(-10)->addMinutes(30),
            ...$attributes,
        ]);
    }

    // ── 1. First free demo succeeds ─────────────────────────────────────

    public function test_student_can_book_first_free_demo_with_instructor(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();

        $booking = app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 3));

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => BookingStatus::Confirmed->value]);
    }

    // ── 2. Same student, same instructor, different time → blocked ──────

    public function test_same_student_cannot_book_second_free_demo_with_same_instructor_at_different_time(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();

        app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 3));

        $this->expectException(FreeDemoAlreadyUsedException::class);

        try {
            app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 7));
        } finally {
            $this->assertDatabaseCount('bookings', 1);
        }
    }

    // ── 3. Same student, different instructor → allowed ──────────────────

    public function test_same_student_can_book_free_demo_with_a_different_instructor(): void
    {
        $teacherA = $this->makeTeacher('maths');
        $teacherB = $this->makeTeacher('physics');
        $student = $this->makeStudent();

        app(BookingServiceInterface::class)->request($this->demoData($student, $teacherA, 3));
        $second = app(BookingServiceInterface::class)->request($this->demoData($student, $teacherB, 3));

        $this->assertDatabaseHas('bookings', ['id' => $second->id, 'instructor_id' => $teacherB->id]);
        $this->assertDatabaseCount('bookings', 2);
    }

    // ── 4. Different student, same instructor → allowed ──────────────────

    public function test_different_student_can_book_free_demo_with_the_same_instructor(): void
    {
        $teacher = $this->makeTeacher();
        $studentA = $this->makeStudent();
        $studentB = $this->makeStudent();

        app(BookingServiceInterface::class)->request($this->demoData($studentA, $teacher, 3));
        $second = app(BookingServiceInterface::class)->request($this->demoData($studentB, $teacher, 4));

        $this->assertDatabaseHas('bookings', ['id' => $second->id, 'student_id' => $studentB->id]);
        $this->assertDatabaseCount('bookings', 2);
    }

    // ── 5. Paid lesson remains available after the demo allowance is used ─

    public function test_paid_booking_remains_available_after_demo_allowance_consumed(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();

        $currency = Currency::factory()->create(['code' => 'USD']);
        $country = Country::factory()->create(['default_currency_id' => $currency->id]);
        $category = AcademicCategory::create(['name' => 'Mathematics', 'slug' => 'mathematics']);
        $subject = Subject::create(['academic_category_id' => $category->id, 'name' => 'Maths', 'slug' => 'maths']);
        UserProfile::updateOrCreate(['user_id' => $student->id], ['country_id' => $country->id]);

        $paidType = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one', 'duration_minutes' => 60]);
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $paidType->id,
            'subject_id' => $subject->id,
            'academic_level_id' => null,
            'country_id' => $country->id,
            'currency_id' => $currency->id,
            'currency_code' => $currency->code,
            'duration_minutes' => 60,
            'amount_minor' => 4999,
        ]);

        app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 3));

        $paidBooking = app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $student->id,
            instructorId: $teacher->id,
            startsAt: $this->slot(5),
            durationMinutes: 60,
            meta: ['subject' => 'maths', 'grade' => 7],
        ));

        $this->assertDatabaseHas('bookings', ['id' => $paidBooking->id, 'booking_type_id' => $paidType->id]);
    }

    // ── 6. Never-confirmed / pre-confirmation cancellation does not consume ─

    public function test_pre_confirmation_cancellation_does_not_consume_eligibility(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();

        $this->historicalDemo($student, $teacher, [
            'status' => BookingStatus::Cancelled,
            'confirmed_at' => null,
            'cancelled_at' => now(),
        ]);

        $booking = app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 3));

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => BookingStatus::Confirmed->value]);
    }

    // ── 7. Confirmed and Completed demos consume eligibility ──────────────

    public function test_confirmed_demo_consumes_eligibility(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();

        $this->historicalDemo($student, $teacher, [
            'status' => BookingStatus::Confirmed,
            'confirmed_at' => now()->subDay(),
        ]);

        $this->expectException(FreeDemoAlreadyUsedException::class);
        app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 3));
    }

    public function test_completed_demo_consumes_eligibility(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();

        $this->historicalDemo($student, $teacher, [
            'status' => BookingStatus::Completed,
            'confirmed_at' => now()->subDays(2),
            'completed_at' => now()->subDay(),
        ]);

        $this->expectException(FreeDemoAlreadyUsedException::class);
        app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 3));
    }

    // ── 8. No-show and post-confirmation cancellation consume eligibility ─

    public function test_no_show_demo_consumes_eligibility(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();

        $this->historicalDemo($student, $teacher, [
            'status' => BookingStatus::NoShow,
            'confirmed_at' => now()->subDays(2),
        ]);

        $this->expectException(FreeDemoAlreadyUsedException::class);
        app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 3));
    }

    /**
     * Conservative anti-abuse assumption (documented in the phase report):
     * the SRS does not explicitly say whether cancelling a *confirmed*
     * demo restores eligibility. This test locks in the safer reading —
     * once a demo reached Confirmed, cancelling it afterward must not
     * grant a second free demo with the same instructor.
     */
    public function test_cancellation_after_confirmation_still_consumes_eligibility(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();

        $this->historicalDemo($student, $teacher, [
            'status' => BookingStatus::Cancelled,
            'confirmed_at' => now()->subDays(2),
            'cancelled_at' => now()->subDay(),
        ]);

        $this->expectException(FreeDemoAlreadyUsedException::class);
        app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 3));
    }

    // ── 9. Soft-deleted (archived) but previously-confirmed demo still consumes ─

    public function test_soft_deleted_but_previously_confirmed_demo_still_consumes_eligibility(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();

        $historical = $this->historicalDemo($student, $teacher, [
            'status' => BookingStatus::Completed,
            'confirmed_at' => now()->subDays(5),
            'completed_at' => now()->subDays(4),
        ]);
        $historical->delete();

        $this->assertSoftDeleted($historical);

        $this->expectException(FreeDemoAlreadyUsedException::class);
        app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 3));
    }

    // ── 10. Direct service invocation cannot bypass enforcement ──────────

    public function test_direct_service_invocation_cannot_bypass_enforcement(): void
    {
        // No Livewire/HTTP layer involved at all — proves the rule lives
        // in the domain/service layer, not in the UI.
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();

        app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 3));

        $this->expectException(FreeDemoAlreadyUsedException::class);
        $this->expectExceptionMessage("You've already used your free demo lesson with this instructor.");

        app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 20));
    }

    // ── 11. Livewire booking wizard surfaces the validation message ──────

    public function test_booking_wizard_displays_the_validation_message(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();
        $student->assignRole('student');

        Livewire::component('frontend.booking.booking-wizard', BookingWizard::class);

        $firstStart = $this->slot(3)->toIso8601String();
        Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'free_demo')
            ->call('selectSubject', 'maths')
            ->call('selectGrade', 5)
            ->call('selectDate', $this->slot(3)->toDateString())
            ->call('selectSlot', $firstStart)
            ->call('submit')
            ->assertSet('step', 7);

        $secondSlot = $this->slot(7);
        $secondStart = $secondSlot->toIso8601String();
        // The wizard's calendar is month-scoped (BookingWizard::loadDates()
        // caps its window at the end of the currently-viewed month) — a
        // fixed +7-day offset from "now" can legitimately land in the
        // following month depending on which day of the month the suite
        // runs on. Advance the wizard's own visible month to match the
        // target date's month before selecting it, exactly as a real
        // user would click "next month", rather than assuming a
        // same-month offset.
        $component = Livewire::actingAs($student)
            ->withQueryParams(['instructor' => $teacher->slug, 'type' => 'free_demo', 'subject' => 'maths'])
            ->test('frontend.booking.booking-wizard')
            ->call('selectGrade', 5);

        for ($month = CarbonImmutable::now('UTC')->startOfMonth(); $month->lt($secondSlot->startOfMonth()); $month = $month->addMonthNoOverflow()) {
            $component->call('nextMonth');
        }

        $component
            ->call('selectDate', $secondSlot->toDateString())
            ->call('selectSlot', $secondStart)
            ->call('submit')
            ->assertSet('step', 6)
            ->assertSet('banner', "You've already used your free demo lesson with this instructor. You can book a paid lesson with them, or try a free demo with a different instructor.");

        $this->assertDatabaseCount('bookings', 1);
    }
}
