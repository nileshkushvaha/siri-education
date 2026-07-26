<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\AvailabilityServiceInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\Contracts\TeacherCandidateRepositoryInterface;
use App\Booking\Contracts\WizardBookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\FreeDemoUnavailableException;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\FeatureSettings;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every scheduling read path used to prepare a NEW free-demo booking
 * (dates, slots, teacher lookups) must respect DemoAvailabilityResolver,
 * matching the authoritative booking-creation rejection and the
 * card/teacher-lookup behavior. Paid scheduling and all already-created
 * demo operations must be completely unaffected.
 */
class DemoSchedulingReadPathTest extends TestCase
{
    use RefreshDatabase;

    private BookingType $demoType;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $this->demoType = BookingType::factory()->create(['key' => 'free_demo', 'duration_minutes' => 30]);
    }

    private function disableDemos(): void
    {
        $settings = app(FeatureSettings::class);
        $settings->demo_lessons_enabled = false;
        $settings->save();
    }

    private function enableDemos(): void
    {
        $settings = app(FeatureSettings::class);
        $settings->demo_lessons_enabled = true;
        $settings->save();
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

    private function paidType(): BookingType
    {
        return BookingType::factory()->paid()->create(['key' => 'paid_one_to_one', 'duration_minutes' => 60]);
    }

    private function window(): array
    {
        return [CarbonImmutable::now('UTC')->addDay()->startOfDay(), CarbonImmutable::now('UTC')->addDays(10)->startOfDay()];
    }

    // ── 1 & 2. Free-demo available dates ────────────────────────────────────

    public function test_free_demo_available_dates_are_returned_when_enabled(): void
    {
        $this->makeTeacher();
        [$from, $to] = $this->window();

        $dates = app(WizardBookingServiceInterface::class)->availableDates('free_demo', 'maths', 5, $from, $to);

        $this->assertNotEmpty($dates);
    }

    public function test_free_demo_available_dates_are_empty_when_disabled(): void
    {
        $this->makeTeacher();
        $this->disableDemos();
        [$from, $to] = $this->window();

        $dates = app(WizardBookingServiceInterface::class)->availableDates('free_demo', 'maths', 5, $from, $to);

        $this->assertCount(0, $dates);
    }

    // ── 3 & 4. Free-demo available slots ────────────────────────────────────

    public function test_free_demo_available_slots_are_returned_when_enabled(): void
    {
        $this->makeTeacher();

        $slots = app(WizardBookingServiceInterface::class)->availableSlots('free_demo', 'maths', 5, CarbonImmutable::now('UTC')->addDays(3));

        $this->assertNotEmpty($slots);
    }

    public function test_free_demo_available_slots_are_empty_when_disabled(): void
    {
        $this->makeTeacher();
        $this->disableDemos();

        $slots = app(WizardBookingServiceInterface::class)->availableSlots('free_demo', 'maths', 5, CarbonImmutable::now('UTC')->addDays(3));

        $this->assertCount(0, $slots);
    }

    // ── 5. Previous-teacher suggestions — not type-scoped, so N/A by design ─

    public function test_previous_teacher_suggestions_do_not_accept_a_type_and_are_unaffected_by_the_toggle(): void
    {
        // Revalidation finding: StudentBookingServiceInterface::previousTeachers()
        // takes no typeKey at all — it is "instructors this student has
        // booked before," for any type, not a demo-specific suggestion.
        // Confirming it is unaffected either way is the correct closure
        // for this item, per the phase brief's own "if that endpoint
        // accepts free_demo" qualifier.
        $teacher = $this->makeTeacher();
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);

        $booking = app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $student->id,
            instructorId: $teacher->id,
            startsAt: CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0),
            durationMinutes: 30,
        ));
        $this->assertSame(BookingStatus::Confirmed, $booking->status);

        $this->disableDemos();

        $previous = app(StudentBookingServiceInterface::class)->previousTeachers($student);

        $this->assertTrue($previous->contains('id', $teacher->id));
    }

    // ── 6 & 7. Paid dates/slots unaffected while demos are disabled ─────────

    public function test_paid_available_dates_are_unaffected_while_demos_are_disabled(): void
    {
        $this->makeTeacher();
        $this->paidType();
        $this->disableDemos();
        [$from, $to] = $this->window();

        $dates = app(WizardBookingServiceInterface::class)->availableDates('paid_one_to_one', 'maths', 5, $from, $to);

        $this->assertNotEmpty($dates);
    }

    public function test_paid_available_slots_are_unaffected_while_demos_are_disabled(): void
    {
        $this->makeTeacher();
        $this->paidType();
        $this->disableDemos();

        $slots = app(WizardBookingServiceInterface::class)->availableSlots('paid_one_to_one', 'maths', 5, CarbonImmutable::now('UTC')->addDays(3));

        $this->assertNotEmpty($slots);
    }

    // ── 8. Paid previous-teacher suggestions unaffected ─────────────────────

    public function test_paid_previous_teacher_suggestions_are_unaffected_while_demos_are_disabled(): void
    {
        $teacher = $this->makeTeacher();
        $paidType = $this->paidType();
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        Booking::factory()->for($paidType, 'type')->create([
            'student_id' => $student->id,
            'instructor_id' => $teacher->id,
            'status' => BookingStatus::Completed,
            'confirmed_at' => now()->subDays(2),
            'completed_at' => now()->subDay(),
        ]);

        $this->disableDemos();

        $previous = app(StudentBookingServiceInterface::class)->previousTeachers($student);

        $this->assertTrue($previous->contains('id', $teacher->id));
    }

    // ── 9. Disabled scheduling does not run unnecessary eligibility/slot queries ─

    public function test_disabled_free_demo_dates_never_queries_teacher_eligibility_or_availability(): void
    {
        $this->makeTeacher();
        $this->disableDemos();

        $candidates = Mockery::mock(TeacherCandidateRepositoryInterface::class);
        $candidates->shouldNotReceive('eligible');
        $candidates->shouldNotReceive('isEligible');
        $this->app->instance(TeacherCandidateRepositoryInterface::class, $candidates);

        $availability = Mockery::mock(AvailabilityServiceInterface::class);
        $availability->shouldNotReceive('slots');
        $this->app->instance(AvailabilityServiceInterface::class, $availability);

        [$from, $to] = $this->window();
        $dates = app(WizardBookingServiceInterface::class)->availableDates('free_demo', 'maths', 5, $from, $to);

        $this->assertCount(0, $dates);
    }

    // ── 10. Re-enabling restores teacher/date/slot responses immediately ───

    public function test_re_enabling_restores_scheduling_responses_immediately(): void
    {
        $teacher = $this->makeTeacher();
        $this->disableDemos();

        [$from, $to] = $this->window();
        $this->assertCount(0, app(WizardBookingServiceInterface::class)->availableDates('free_demo', 'maths', 5, $from, $to));
        $this->assertCount(0, app(StudentBookingServiceInterface::class)->availableTeachers('free_demo', 'maths', 5));

        $this->enableDemos();

        $this->assertNotEmpty(app(WizardBookingServiceInterface::class)->availableDates('free_demo', 'maths', 5, $from, $to));
        $this->assertTrue(app(StudentBookingServiceInterface::class)->availableTeachers('free_demo', 'maths', 5)->contains('id', $teacher->id));
    }

    // ── 11. Booking-type-inactive behavior remains consistent (unchanged) ──

    public function test_inactive_booking_type_still_throws_rather_than_returning_empty(): void
    {
        $this->makeTeacher();
        $this->demoType->update(['is_active' => false]);
        [$from, $to] = $this->window();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('Booking type "free_demo" is not currently accepting bookings.');

        app(WizardBookingServiceInterface::class)->availableDates('free_demo', 'maths', 5, $from, $to);
    }

    public function test_inactive_booking_type_still_throws_for_slots(): void
    {
        $this->makeTeacher();
        $this->demoType->update(['is_active' => false]);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('Booking type "free_demo" is not currently accepting bookings.');

        app(WizardBookingServiceInterface::class)->availableSlots('free_demo', 'maths', 5, CarbonImmutable::now('UTC')->addDays(3));
    }

    // ── 12. Direct service invocation cannot bypass gating ─────────────────

    public function test_direct_service_invocation_of_every_scheduling_method_is_gated(): void
    {
        $this->makeTeacher();
        $this->disableDemos();
        [$from, $to] = $this->window();

        // No Livewire/HTTP layer involved for the service-level checks.
        $this->assertCount(0, app(WizardBookingServiceInterface::class)->availableDates('free_demo', 'maths', 5, $from, $to));
        $this->assertCount(0, app(WizardBookingServiceInterface::class)->availableSlots('free_demo', 'maths', 5, CarbonImmutable::now('UTC')->addDays(3)));
        $this->assertCount(0, app(StudentBookingServiceInterface::class)->availableTeachers('free_demo', 'maths', 5));
    }

    // ── 13. The authoritative booking rejection still works ────────────────

    public function test_authoritative_booking_creation_rejection_is_unaffected(): void
    {
        $teacher = $this->makeTeacher();
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $this->disableDemos();

        $this->expectException(FreeDemoUnavailableException::class);

        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $student->id,
            instructorId: $teacher->id,
            startsAt: CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0),
            durationMinutes: 30,
        ));
    }

    // ── 14. Existing demo bookings remain operational ──────────────────────

    public function test_existing_demo_booking_remains_operational_after_scheduling_paths_are_disabled(): void
    {
        $teacher = $this->makeTeacher();
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);

        $booking = app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $student->id,
            instructorId: $teacher->id,
            startsAt: CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0),
            durationMinutes: 30,
        ));

        $this->disableDemos();

        $cancelled = app(BookingServiceInterface::class)->cancel($booking, new CancelBookingData(
            BookingActor::Student,
            'No longer needed.',
        ));

        $this->assertSame(BookingStatus::Cancelled, $cancelled->status);
    }

    // ── Contract test: every known new-booking preparation method is gated ──

    /** @return array<string, array{0: Closure(): Collection}> */
    public static function schedulingReadPathProvider(): array
    {
        $window = fn () => [CarbonImmutable::now('UTC')->addDay()->startOfDay(), CarbonImmutable::now('UTC')->addDays(10)->startOfDay()];

        return [
            'WizardBookingServiceInterface::availableDates' => [function () use ($window): Collection {
                [$from, $to] = $window();

                return app(WizardBookingServiceInterface::class)->availableDates('free_demo', 'maths', 5, $from, $to);
            }],
            'WizardBookingServiceInterface::availableSlots' => [fn (): Collection => app(WizardBookingServiceInterface::class)
                ->availableSlots('free_demo', 'maths', 5, CarbonImmutable::now('UTC')->addDays(3))],
            'StudentBookingServiceInterface::availableTeachers' => [fn (): Collection => app(StudentBookingServiceInterface::class)
                ->availableTeachers('free_demo', 'maths', 5)],
        ];
    }

    #[DataProvider('schedulingReadPathProvider')]
    public function test_every_known_scheduling_read_path_is_gated_while_demos_are_disabled(Closure $call): void
    {
        $this->makeTeacher();
        $this->disableDemos();

        $this->assertCount(0, $call());
    }

    public function test_the_http_slots_endpoint_is_gated_while_demos_are_disabled(): void
    {
        $teacher = $this->makeTeacher();
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->disableDemos();

        $this->actingAs($student)
            ->getJson('/dashboard/bookings/slots?'.http_build_query([
                'type' => 'free_demo',
                'teacher_id' => $teacher->id,
                'date' => CarbonImmutable::now('UTC')->addDays(3)->toDateString(),
            ]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_http_slots_endpoint_is_unaffected_for_paid_bookings(): void
    {
        $teacher = $this->makeTeacher();
        $this->paidType();
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->disableDemos();

        $this->actingAs($student)
            ->getJson('/dashboard/bookings/slots?'.http_build_query([
                'type' => 'paid_one_to_one',
                'teacher_id' => $teacher->id,
                'date' => CarbonImmutable::now('UTC')->addDays(3)->toDateString(),
            ]))
            ->assertOk();
    }
}
