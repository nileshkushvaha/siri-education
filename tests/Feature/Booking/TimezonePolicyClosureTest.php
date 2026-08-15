<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\AvailabilityServiceInterface;
use App\Booking\Contracts\WizardBookingServiceInterface;
use App\Booking\DTOs\AvailabilityQueryData;
use App\Booking\DTOs\RecurrenceData;
use App\Booking\DTOs\WizardBookingData;
use App\Booking\Enums\RecurrenceFrequency;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\BookingSettings;
use App\Settings\GeneralSettings;
use App\Support\Timezone\LocalWallClock;
use App\Support\Timezone\ViewerDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * TZ-6 — the three remaining timezone POLICY decisions, closed.
 *
 *  1. A recurring series is anchored to the INSTRUCTOR's availability
 *     clock. The weekly rule is the instructor's, so it must keep
 *     meaning the same time to them all year. This is a real behaviour
 *     change from TZ-2A, which characterized the previous student-local
 *     anchor.
 *
 *  2. A wall-clock reading that daylight saving makes impossible or
 *     double is never silently shifted or arbitrarily resolved.
 *
 *  3. Anonymous visitors get a validated browser timezone for DISPLAY
 *     only, falling back to the platform default.
 */
class TimezonePolicyClosureTest extends TestCase
{
    use CreatesStudentLessonPrices, RefreshDatabase;

    /** @var array{type: BookingType, country: Country, currency: Currency} */
    private array $priced;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $this->priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR');
        $this->seedLessonSubject('maths');

        $settings = app(BookingSettings::class);
        $settings->max_daily_bookings_per_teacher = null;
        $settings->maximum_advance_booking_days = 3650;
        $settings->save();
    }

    private function instructor(string $timezone, string $start = '00:00:00', string $end = '23:00:00'): User
    {
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $teacher->id], [
            'instructor_status' => 'approved', 'profile_visibility' => 'public', 'timezone' => $timezone,
        ]);
        TeacherSubject::factory()->state(['teacher_id' => $teacher->id])->subject('maths', 1, 12)->create();

        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()
                ->state(['teacher_id' => $teacher->id, 'timezone' => $timezone])
                ->forDay($day)->between($start, $end)->create();
        }

        return $teacher;
    }

    private function student(string $timezone): User
    {
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $student->id], ['timezone' => $timezone]);
        $this->assignBillingCountry($student, $this->priced['country']);

        return $student->fresh();
    }

    /** @return Collection<int, Booking> */
    private function bookSeries(User $student, User $teacher, string $firstLocalStart, string $studentTimezone, int $occurrences = 3)
    {
        Auth::login($student);

        $result = app(WizardBookingServiceInterface::class)->bookRecurring(
            new WizardBookingData(
                typeKey: 'paid_one_to_one', subject: 'maths', grade: 8,
                startsAt: CarbonImmutable::parse($firstLocalStart, $studentTimezone),
                timezone: $studentTimezone, teacherId: $teacher->id,
            ),
            new RecurrenceData(occurrences: $occurrences, frequency: RecurrenceFrequency::Weekly),
        );

        $this->assertSame([], $result->failures);

        return Booking::query()->whereIn('id', $result->booked->pluck('id'))->orderBy('starts_at')->get();
    }

    // ── Decision 1 · recurrence follows the instructor ───────────────────

    public function test_a_series_holds_the_instructors_wall_clock_across_spring_forward(): void
    {
        // London springs forward 2027-03-28. Monday 19:00 London must
        // stay Monday 19:00 London on both sides of it.
        $instructor = $this->instructor('Europe/London');
        $student = $this->student('Asia/Kolkata');

        $bookings = $this->bookSeries($student, $instructor, '2027-03-23 00:30', 'Asia/Kolkata');

        foreach ($bookings as $index => $booking) {
            $this->assertSame(
                '19:00',
                $booking->starts_at->setTimezone('Europe/London')->format('H:i'),
                "occurrence {$index} drifted in the INSTRUCTOR's clock",
            );
        }

        // The student's clock is what moves — and that is the accepted
        // trade of anchoring to the instructor.
        $studentTimes = $bookings->map(fn (Booking $b): string => $b->starts_at->setTimezone('Asia/Kolkata')->format('H:i'))->unique()->values();
        $this->assertGreaterThan(1, $studentTimes->count(), 'the student side absorbs the transition');
    }

    public function test_a_series_holds_the_instructors_wall_clock_across_fall_back(): void
    {
        // New York falls back 2026-11-01.
        $instructor = $this->instructor('America/New_York');
        $student = $this->student('Europe/London');

        $bookings = $this->bookSeries($student, $instructor, '2026-10-28 23:00', 'Europe/London');

        foreach ($bookings as $booking) {
            $this->assertSame('19:00', $booking->starts_at->setTimezone('America/New_York')->format('H:i'));
        }
    }

    public function test_the_instructor_is_stable_when_the_two_countries_change_clocks_on_different_dates(): void
    {
        // THE case TZ-2A surfaced and left open. The US springs forward
        // 2027-03-14, the UK 2027-03-28: for the fortnight between, a
        // student-anchored series walked the instructor's slot by an
        // hour, straight out of the availability window that created it.
        $instructor = $this->instructor('Europe/London');
        $student = $this->student('America/New_York');

        $bookings = $this->bookSeries($student, $instructor, '2027-03-08 14:00', 'America/New_York', 4);

        $instructorTimes = $bookings->map(fn (Booking $b): string => $b->starts_at->setTimezone('Europe/London')->format('H:i'))->unique()->values();
        $studentTimes = $bookings->map(fn (Booking $b): string => $b->starts_at->setTimezone('America/New_York')->format('H:i'))->unique()->values();

        $this->assertCount(1, $instructorTimes, 'the instructor keeps one teaching time all series');
        $this->assertGreaterThan(1, $studentTimes->count(), 'the student absorbs the offset difference');
    }

    public function test_every_occurrence_is_still_validated_against_real_availability(): void
    {
        // Preserving the instructor's wall clock does not make an
        // occurrence bookable — leave, holidays, clashes and caps all
        // still apply. A window that only covers mornings must refuse an
        // evening series.
        $instructor = $this->instructor('Europe/London', '09:00:00', '12:00:00');
        $student = $this->student('Europe/London');

        Auth::login($student);

        $this->expectException(BookingException::class);

        app(WizardBookingServiceInterface::class)->bookRecurring(
            new WizardBookingData(
                typeKey: 'paid_one_to_one', subject: 'maths', grade: 8,
                startsAt: CarbonImmutable::parse('2027-05-03 19:00', 'Europe/London'),
                timezone: 'Europe/London', teacherId: $instructor->id,
            ),
            new RecurrenceData(occurrences: 3, frequency: RecurrenceFrequency::Weekly),
        );
    }

    // ── Decision 2 · DST-invalid wall clock is refused, atomically ───────

    public function test_the_wall_clock_validator_detects_both_conditions(): void
    {
        $this->assertSame(LocalWallClock::NONEXISTENT, LocalWallClock::classify('2027-03-28 01:30', 'Europe/London'));
        $this->assertSame(LocalWallClock::AMBIGUOUS, LocalWallClock::classify('2026-11-01 01:30', 'America/New_York'));
        $this->assertSame(LocalWallClock::VALID, LocalWallClock::classify('2026-08-15 09:00', 'Asia/Kolkata'));

        // PHP's silent answers, which is exactly why this class exists.
        $this->assertSame('02:30', CarbonImmutable::parse('2027-03-28 01:30', 'Europe/London')->format('H:i'));
    }

    public function test_a_series_crossing_a_nonexistent_local_time_is_refused_with_nothing_created(): void
    {
        $instructor = $this->instructor('Europe/London');
        $student = $this->student('Europe/London');
        Auth::login($student);

        $before = Booking::query()->count();

        try {
            app(WizardBookingServiceInterface::class)->bookRecurring(
                new WizardBookingData(
                    typeKey: 'paid_one_to_one', subject: 'maths', grade: 8,
                    // 01:30 on 2027-03-21 is fine; a week later it does not exist.
                    startsAt: CarbonImmutable::parse('2027-03-21 01:30', 'Europe/London'),
                    timezone: 'Europe/London', teacherId: $instructor->id,
                ),
                new RecurrenceData(occurrences: 3, frequency: RecurrenceFrequency::Weekly),
            );
            $this->fail('a series containing an impossible local time must be refused');
        } catch (BookingException $e) {
            $this->assertStringContainsString('does not exist', $e->getMessage());
            $this->assertStringNotContainsString('DateTime', $e->getMessage(), 'no implementation vocabulary');
        }

        // Atomic: the FIRST occurrence was perfectly valid and must still
        // not have been created.
        $this->assertSame($before, Booking::query()->count());
    }

    public function test_a_series_crossing_an_ambiguous_local_time_is_refused(): void
    {
        $instructor = $this->instructor('America/New_York');
        $student = $this->student('America/New_York');
        Auth::login($student);

        $before = Booking::query()->count();

        try {
            app(WizardBookingServiceInterface::class)->bookRecurring(
                new WizardBookingData(
                    typeKey: 'paid_one_to_one', subject: 'maths', grade: 8,
                    startsAt: CarbonImmutable::parse('2026-10-25 01:30', 'America/New_York'),
                    timezone: 'America/New_York', teacherId: $instructor->id,
                ),
                new RecurrenceData(occurrences: 3, frequency: RecurrenceFrequency::Weekly),
            );
            $this->fail('a series containing a doubled local time must be refused');
        } catch (BookingException $e) {
            $this->assertStringContainsString('happens twice', $e->getMessage());
        }

        $this->assertSame($before, Booking::query()->count());
    }

    public function test_availability_does_not_publish_a_slot_at_a_nonexistent_local_time(): void
    {
        // TZ-AUD-022 on the availability side: the window is not silently
        // shifted an hour, it is simply not offered that one day.
        $instructor = $this->instructor('Europe/London', '01:00:00', '02:00:00');

        $day = CarbonImmutable::parse('2027-03-28', 'Europe/London');

        $slots = app(AvailabilityServiceInterface::class)->slots(new AvailabilityQueryData(
            instructorId: $instructor->id, typeKey: 'paid_one_to_one',
            from: $day->startOfDay()->utc(), to: $day->addDay()->startOfDay()->utc(),
            timezone: 'Europe/London',
        ));

        $this->assertTrue($slots->isEmpty(), 'the 01:00-02:00 window does not exist on the spring-forward date');
    }

    public function test_the_instructors_weekly_rule_is_untouched_on_other_days(): void
    {
        // Only the impossible occurrence disappears — the rule itself and
        // every other week stay exactly as published.
        $instructor = $this->instructor('Europe/London', '01:00:00', '02:00:00');

        $normalDay = CarbonImmutable::parse('2027-04-04', 'Europe/London');

        $slots = app(AvailabilityServiceInterface::class)->slots(new AvailabilityQueryData(
            instructorId: $instructor->id, typeKey: 'paid_one_to_one',
            from: $normalDay->startOfDay()->utc(), to: $normalDay->addDay()->startOfDay()->utc(),
            timezone: 'Europe/London',
        ));

        $this->assertTrue($slots->isNotEmpty());
        $this->assertDatabaseCount('teacher_availability', 7);
    }

    // ── Decision 3 · anonymous viewers ──────────────────────────────────

    public function test_an_anonymous_visitor_uses_a_validated_browser_timezone_for_display(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->default_timezone = 'UTC';
        $settings->save();

        $this->assertTrue(ViewerDateTime::rememberBrowserTimezone('Asia/Kolkata'));
        $this->assertSame('Asia/Kolkata', ViewerDateTime::timezoneFor());
    }

    public function test_an_invalid_browser_timezone_falls_back_to_the_platform_default(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->default_timezone = 'Europe/Lisbon';
        $settings->save();

        foreach (['EST', '+05:30', 'Not/AZone', '', null] as $bogus) {
            $this->assertFalse(ViewerDateTime::rememberBrowserTimezone($bogus));
        }

        $this->assertSame('Europe/Lisbon', ViewerDateTime::timezoneFor());
    }

    public function test_an_authenticated_profile_timezone_beats_any_browser_hint(): void
    {
        ViewerDateTime::rememberBrowserTimezone('America/New_York');

        $student = $this->student('America/Los_Angeles');
        Auth::login($student);

        $this->assertSame('America/Los_Angeles', ViewerDateTime::timezoneFor());
    }

    public function test_a_browser_timezone_is_never_persisted_to_a_profile(): void
    {
        $student = $this->student('America/Los_Angeles');
        Auth::login($student);

        ViewerDateTime::rememberBrowserTimezone('America/New_York');

        $this->assertSame('America/Los_Angeles', $student->fresh()->profile->timezone);
    }
}
