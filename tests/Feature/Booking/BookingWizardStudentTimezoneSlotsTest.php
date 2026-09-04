<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\AvailabilityRepositoryInterface;
use App\Booking\Contracts\AvailabilityServiceInterface;
use App\Booking\Contracts\WizardBookingServiceInterface;
use App\Booking\DTOs\AvailabilityQueryData;
use App\Booking\DTOs\WizardBookingData;
use App\Booking\Enums\Weekday;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\UserTimezoneResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesAcademicBookingContext;
use Tests\TestCase;

/**
 * Phase 3.1 §23-§30/§41 — student-timezone slot presentation. The audit
 * in §23-§28 found the pipeline already correct end to end
 * (UserTimezoneResolver -> BookingWizard::$timezone ->
 * AvailabilityQueryData::$timezone -> AvailabilityService::slots()
 * computing everything in UTC and converting only at the final step);
 * these tests close the gap in explicit, single-purpose coverage rather
 * than restructure anything. Assertions compare underlying instants
 * (via ->equalTo()/->getTimestamp()), not only formatted strings, and
 * timezone conversion is always via Carbon — never a manually
 * hardcoded offset.
 */
class BookingWizardStudentTimezoneSlotsTest extends TestCase
{
    use CreatesAcademicBookingContext, RefreshDatabase;

    /** @var array<string, mixed> */
    private array $academicContext;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        BookingType::factory()->create(['key' => 'free_demo', 'name' => 'Free Demo', 'duration_minutes' => 30, 'sort_order' => 1]);

        // Country-aware academics are mandatory for any booking; this file
        // is about timezone/slot fidelity, so the chain is pure precondition.
        $this->bootAcademicBookingContext();
        $this->academicContext = $this->seedAcademicContext();
    }

    private function teacher(): User
    {
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $teacher->assignRole('instructor');
        UserProfile::updateOrCreate(['user_id' => $teacher->id], ['instructor_status' => 'approved', 'profile_visibility' => 'public']);

        TeacherSubject::factory()->create([
            'teacher_id' => $teacher->id,
            'subject' => $this->academicContext['subject']->name,
            'subject_id' => $this->academicContext['subject']->id,
            'grade_from' => 1,
            'grade_to' => 12,
        ]);
        $this->makeInstructorEligible(
            $teacher,
            $this->academicContext['system'],
            $this->academicContext['curriculum'],
        );

        return $teacher;
    }

    private function student(string $timezone): User
    {
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $student->id], [
            'timezone' => $timezone,
            'country_id' => $this->academicContext['country']->id,
        ]);

        return $student;
    }

    private function availability(User $teacher, Weekday $day, string $start, string $end, string $timezone): TeacherAvailability
    {
        return TeacherAvailability::factory()
            ->state(['teacher_id' => $teacher->id, 'timezone' => $timezone])
            ->forDay($day)
            ->between($start, $end)
            ->create();
    }

    /** The next date at/after $from whose Carbon dayOfWeek matches the given Weekday. */
    private function nextWeekday(CarbonImmutable $from, Weekday $weekday): CarbonImmutable
    {
        $date = $from;
        while ($date->dayOfWeek !== $weekday->value) {
            $date = $date->addDay();
        }

        return $date->startOfDay();
    }

    private function firstSlot(User $teacher, CarbonImmutable $from, CarbonImmutable $to, string $timezone)
    {
        return app(AvailabilityServiceInterface::class)
            ->slots(new AvailabilityQueryData($teacher->id, 'free_demo', $from, $to, $timezone))
            ->sortBy(fn ($slot) => $slot->startsAt->getTimestamp())
            ->first();
    }

    // ── Same timezone ─────────────────────────────────────────────────────

    public function test_instructor_and_student_in_the_same_timezone_see_the_slot_unchanged(): void
    {
        $teacher = $this->teacher();
        $monday = $this->nextWeekday(CarbonImmutable::now('UTC')->addWeek(), Weekday::Monday);
        $this->availability($teacher, Weekday::Monday, '09:00:00', '17:00:00', 'UTC');

        $slot = $this->firstSlot($teacher, $monday, $monday->endOfDay(), 'UTC');

        $this->assertSame('09:00', $slot->startsAt->format('H:i'));
        $this->assertSame($monday->toDateString(), $slot->startsAt->toDateString());
    }

    // ── Different timezone ───────────────────────────────────────────────

    public function test_different_instructor_and_student_timezones_convert_to_the_same_instant(): void
    {
        $teacher = $this->teacher();
        $monday = $this->nextWeekday(CarbonImmutable::now('Asia/Kolkata')->addWeek(), Weekday::Monday);
        $this->availability($teacher, Weekday::Monday, '10:00:00', '11:00:00', 'Asia/Kolkata');

        $window = [$monday->subDay(), $monday->addDay()->endOfDay()];

        $utcSlot = $this->firstSlot($teacher, ...$window, timezone: 'UTC');
        $studentSlot = $this->firstSlot($teacher, ...$window, timezone: 'America/New_York');

        // Same underlying instant, expressed in two different display timezones.
        $this->assertTrue($utcSlot->startsAt->equalTo($studentSlot->startsAt));
        $this->assertSame($utcSlot->startsAt->getTimestamp(), $studentSlot->startsAt->getTimestamp());

        // The student sees it converted to THEIR timezone — never UTC, never the instructor's.
        $this->assertSame('America/New_York', $studentSlot->startsAt->tzName);
        $this->assertSame(
            $utcSlot->startsAt->setTimezone('America/New_York')->format('g:i A, M j'),
            $studentSlot->startsAt->format('g:i A, M j'),
        );
    }

    // ── Date crossover ────────────────────────────────────────────────────

    public function test_late_instructor_slot_crosses_into_the_next_calendar_date_for_the_student(): void
    {
        $teacher = $this->teacher();
        $monday = $this->nextWeekday(CarbonImmutable::now('UTC')->addWeek(), Weekday::Monday);
        // 11:00 PM-11:30 PM UTC Monday.
        $this->availability($teacher, Weekday::Monday, '23:00:00', '23:30:00', 'UTC');

        // Asia/Kolkata is a fixed +05:30 offset (no DST) — deterministic.
        $slot = $this->firstSlot($teacher, $monday, $monday->addDay()->endOfDay(), 'Asia/Kolkata');

        $this->assertSame($monday->addDay()->toDateString(), $slot->startsAt->toDateString());
        $this->assertSame('Asia/Kolkata', $slot->startsAt->tzName);
    }

    public function test_early_instructor_slot_crosses_into_the_previous_calendar_date_for_the_student(): void
    {
        $teacher = $this->teacher();
        $monday = $this->nextWeekday(CarbonImmutable::now('UTC')->addWeek(), Weekday::Monday);
        // Midnight-12:30 AM UTC Monday.
        $this->availability($teacher, Weekday::Monday, '00:00:00', '00:30:00', 'UTC');

        // Pacific/Honolulu is a fixed -10:00 offset (no DST) — deterministic.
        $slot = $this->firstSlot($teacher, $monday->subDay(), $monday->endOfDay(), 'Pacific/Honolulu');

        $this->assertSame($monday->subDay()->toDateString(), $slot->startsAt->toDateString());
        $this->assertSame('Pacific/Honolulu', $slot->startsAt->tzName);
    }

    // ── DST — deterministic, never self-skips ───────────────────────────────
    //
    // Europe/London's DST rule is fixed by law (EU rule, unchanged since
    // 1996): clocks spring forward on the last Sunday of March and fall
    // back on the last Sunday of October, both at 01:00 UTC. Rather than
    // scanning PHP's transition table for "the next transition within N
    // days of today" (which self-skips on the — increasingly common, as
    // the booking window shrinks — run where none falls in range), these
    // tests freeze "now" (Carbon::setTestNow via travelTo()) to a fixed
    // date shortly before a known, hardcoded transition date, so the
    // transition always falls inside the bookable window and the test
    // always executes, on every run, regardless of the real calendar date.

    /** The last Sunday of the given UTC month/year — computed, not guessed, from the fixed civil-calendar rule. */
    private function lastSundayOf(int $year, int $month): CarbonImmutable
    {
        $date = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC')->endOfMonth()->startOfDay();

        while ($date->dayOfWeek !== CarbonImmutable::SUNDAY) {
            $date = $date->subDay();
        }

        return $date;
    }

    public function test_spring_forward_dst_transition_is_reflected_in_student_local_time(): void
    {
        $this->travelTo(CarbonImmutable::create(2027, 3, 1, 12, 0, 0, 'UTC'));

        $instructorTimezone = 'Europe/London';
        $dstSunday = $this->lastSundayOf(2027, 3); // 2027-03-28 — clocks spring forward GMT (UTC+0) -> BST (UTC+1) at 01:00 UTC.
        $standardSunday = $dstSunday->subWeek(); // still GMT.

        $teacher = $this->teacher();
        TeacherAvailability::factory()->create([
            'teacher_id' => $teacher->id,
            'day_of_week' => Weekday::Sunday,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'timezone' => $instructorTimezone,
        ]);

        // Student in a fixed-offset timezone (no DST of its own), so any
        // change observed here is purely the instructor-side London transition.
        $studentTimezone = 'Asia/Kolkata';

        $standardSlot = $this->firstSlot($teacher, $standardSunday, $standardSunday->addDay(), $studentTimezone);
        $dstSlot = $this->firstSlot($teacher, $dstSunday, $dstSunday->addDay(), $studentTimezone);

        $this->assertNotNull($standardSlot);
        $this->assertNotNull($dstSlot);

        // Same instructor-local wall-clock window (09:00 London) resolves to
        // a DIFFERENT underlying UTC instant either side of the transition —
        // GMT (UTC+0): 09:00 local == 09:00 UTC; BST (UTC+1): 09:00 local == 08:00 UTC.
        $this->assertSame('09:00', $standardSlot->startsAt->utc()->format('H:i'));
        $this->assertSame('08:00', $dstSlot->startsAt->utc()->format('H:i'));

        // The student-visible local time (fixed +05:30, Kolkata) reflects
        // that real instant shift — never a fixed delta.
        $this->assertSame('2:30 PM', $standardSlot->startsAt->format('g:i A'));
        $this->assertSame('1:30 PM', $dstSlot->startsAt->format('g:i A'));
        $this->assertSame($studentTimezone, $dstSlot->startsAt->tzName);
    }

    public function test_fall_back_dst_transition_is_reflected_in_student_local_time(): void
    {
        $this->travelTo(CarbonImmutable::create(2027, 10, 1, 12, 0, 0, 'UTC'));

        $instructorTimezone = 'Europe/London';
        $standardSunday = $this->lastSundayOf(2027, 10); // 2027-10-31 — clocks fall back BST (UTC+1) -> GMT (UTC+0) at 02:00 BST.
        $dstSunday = $standardSunday->subWeek(); // still BST.

        $teacher = $this->teacher();
        TeacherAvailability::factory()->create([
            'teacher_id' => $teacher->id,
            'day_of_week' => Weekday::Sunday,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'timezone' => $instructorTimezone,
        ]);

        $studentTimezone = 'Asia/Kolkata';

        $dstSlot = $this->firstSlot($teacher, $dstSunday, $dstSunday->addDay(), $studentTimezone);
        $standardSlot = $this->firstSlot($teacher, $standardSunday, $standardSunday->addDay(), $studentTimezone);

        $this->assertNotNull($dstSlot);
        $this->assertNotNull($standardSlot);

        // BST (UTC+1): 09:00 local == 08:00 UTC; GMT (UTC+0), after falling back: 09:00 local == 09:00 UTC.
        $this->assertSame('08:00', $dstSlot->startsAt->utc()->format('H:i'));
        $this->assertSame('09:00', $standardSlot->startsAt->utc()->format('H:i'));

        $this->assertSame('1:30 PM', $dstSlot->startsAt->format('g:i A'));
        $this->assertSame('2:30 PM', $standardSlot->startsAt->format('g:i A'));
        $this->assertSame($studentTimezone, $standardSlot->startsAt->tzName);
    }

    // ── Submission persists the same instant ────────────────────────────────

    public function test_booking_the_displayed_local_slot_persists_the_same_underlying_instant(): void
    {
        $teacher = $this->teacher();
        $monday = $this->nextWeekday(CarbonImmutable::now('UTC')->addWeek(), Weekday::Monday);
        $this->availability($teacher, Weekday::Monday, '09:00:00', '17:00:00', 'UTC');

        $studentTimezone = 'America/New_York';
        $student = $this->student($studentTimezone);
        $slot = $this->firstSlot($teacher, $monday, $monday->endOfDay(), $studentTimezone);

        $this->actingAs($student);
        $booking = app(WizardBookingServiceInterface::class)->book(new WizardBookingData(
            typeKey: 'free_demo',
            subject: $this->academicContext['subject']->name,
            grade: 6,
            startsAt: $slot->startsAt,
            timezone: $studentTimezone,
            teacherId: $teacher->id,
            educationSystemId: $this->academicContext['system']->id,
            educationSystemLevelId: $this->academicContext['level']->id,
            subjectId: $this->academicContext['subject']->id,
            curriculumId: $this->academicContext['curriculum']->id,
        ));

        // The persisted canonical instant matches the exact instant the
        // student selected — no re-derivation, no drift.
        $this->assertTrue($booking->starts_at->equalTo($slot->startsAt));
        $this->assertSame($studentTimezone, $booking->timezone);
    }

    // ── Historical display stability ────────────────────────────────────────

    public function test_changing_the_students_profile_timezone_after_booking_does_not_reinterpret_the_historical_selection(): void
    {
        $teacher = $this->teacher();
        $monday = $this->nextWeekday(CarbonImmutable::now('UTC')->addWeek(), Weekday::Monday);
        $this->availability($teacher, Weekday::Monday, '09:00:00', '17:00:00', 'UTC');

        $originalTimezone = 'Asia/Kolkata';
        $student = $this->student($originalTimezone);
        $slot = $this->firstSlot($teacher, $monday, $monday->endOfDay(), $originalTimezone);

        $this->actingAs($student);
        $booking = app(WizardBookingServiceInterface::class)->book(new WizardBookingData(
            typeKey: 'free_demo',
            subject: $this->academicContext['subject']->name,
            grade: 6,
            startsAt: $slot->startsAt,
            timezone: $originalTimezone,
            teacherId: $teacher->id,
            educationSystemId: $this->academicContext['system']->id,
            educationSystemLevelId: $this->academicContext['level']->id,
            subjectId: $this->academicContext['subject']->id,
            curriculumId: $this->academicContext['curriculum']->id,
        ));

        $this->assertSame($originalTimezone, $booking->timezone);

        // Student later moves and updates their profile timezone.
        $student->profile->update(['timezone' => 'Pacific/Honolulu']);

        // Booking::timezone (the frozen, student-facing timezone AT
        // selection) must not be silently re-derived from the now-changed
        // live profile — UserTimezoneResolver is for notifications
        // going forward, not for reinterpreting this historical record.
        $booking->refresh();
        $this->assertSame($originalTimezone, $booking->timezone);
        $this->assertNotSame(UserTimezoneResolver::resolve($student->fresh()), $booking->timezone);
    }

    /**
     * Regression: a New York student's local day overlaps two Kolkata
     * instructor days. windowsFor() legitimately returns both days'
     * windows (they overlap the range), but the slots handed back for
     * "this date" must all START within that date in the student's
     * timezone — previously ~29 hours of slots came back and the wizard
     * showed e.g. 7:30 AM twice with no date to tell them apart.
     */
    public function test_slots_for_one_student_local_day_never_spill_into_the_next_or_previous_day(): void
    {
        $studentTimezone = 'America/New_York';
        $teacher = $this->teacher();
        // Every day of the week, all day, so both overlapping instructor days produce windows.
        foreach (Weekday::cases() as $weekday) {
            $this->availability($teacher, $weekday, '00:00:00', '23:30:00', 'Asia/Kolkata');
        }

        $localDay = CarbonImmutable::now($studentTimezone)->addDays(7)->startOfDay();

        $slots = app(AvailabilityServiceInterface::class)->slots(
            new AvailabilityQueryData($teacher->id, 'free_demo', $localDay, $localDay->addDay(), $studentTimezone),
        );

        $this->assertNotEmpty($slots);

        foreach ($slots as $slot) {
            $this->assertSame($studentTimezone, $slot->startsAt->tzName);
            $this->assertSame(
                $localDay->toDateString(),
                $slot->startsAt->toDateString(),
                sprintf('Slot %s does not fall on the requested student-local day %s', $slot->startsAt->toIso8601String(), $localDay->toDateString()),
            );
        }

        // Nothing at or after the next local midnight, nothing before this one.
        $this->assertTrue($slots->every(fn ($slot): bool => $slot->startsAt->greaterThanOrEqualTo($localDay) && $slot->startsAt->lessThan($localDay->addDay())));

        // No two slots may collapse onto the same clock label — that was the visible symptom.
        $labels = $slots->map(fn ($slot): string => $slot->startsAt->format('g:i A'));
        $this->assertSame($labels->count(), $labels->unique()->count());
    }

    /**
     * Regression: production windows read 00:00-23:59 (a time input cannot
     * say 24:00). Read literally, the last lesson of the instructor's day
     * (23:30-00:00 for a 30-min demo, 23:00-00:00 for an hour) fell one
     * minute outside the window and was never offered — for a New York
     * student that was the missing "1:30 PM" in an otherwise full day.
     */
    public function test_a_window_ending_at_2359_still_offers_the_last_slot_before_midnight(): void
    {
        $teacher = $this->teacher();
        $monday = $this->nextWeekday(CarbonImmutable::now('Asia/Kolkata')->addWeek(), Weekday::Monday);
        $this->availability($teacher, Weekday::Monday, '00:00:00', '23:59:00', 'Asia/Kolkata');

        $slots = app(AvailabilityServiceInterface::class)->slots(
            new AvailabilityQueryData($teacher->id, 'free_demo', $monday, $monday->addDay(), 'Asia/Kolkata'),
        );

        $lastStart = $slots->map(fn ($slot) => $slot->startsAt->format('H:i'))->sort()->last();

        $this->assertSame('23:30', $lastStart, 'The 23:30-00:00 slot must be generated from a 00:00-23:59 window.');

        // …and booking that slot is accepted by the same coverage rule.
        $slot = $slots->first(fn ($slot) => $slot->startsAt->format('H:i') === '23:30');
        $this->assertTrue(
            app(AvailabilityRepositoryInterface::class)->windowCovers($teacher->id, $slot->startsAt, $slot->endsAt),
        );
    }

    /** 23:58 is not "midnight": the literal reading still applies, so the last slot is dropped as before. */
    public function test_a_window_ending_at_2358_is_taken_literally(): void
    {
        $teacher = $this->teacher();
        $monday = $this->nextWeekday(CarbonImmutable::now('Asia/Kolkata')->addWeek(), Weekday::Monday);
        $this->availability($teacher, Weekday::Monday, '00:00:00', '23:58:00', 'Asia/Kolkata');

        $slots = app(AvailabilityServiceInterface::class)->slots(
            new AvailabilityQueryData($teacher->id, 'free_demo', $monday, $monday->addDay(), 'Asia/Kolkata'),
        );

        $this->assertSame('23:00', $slots->map(fn ($slot) => $slot->startsAt->format('H:i'))->sort()->last());
    }
}
