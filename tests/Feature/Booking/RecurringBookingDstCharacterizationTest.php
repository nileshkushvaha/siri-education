<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\WizardBookingServiceInterface;
use App\Booking\DTOs\RecurrenceData;
use App\Booking\DTOs\WizardBookingData;
use App\Booking\Enums\RecurrenceFrequency;
use App\Booking\Enums\Weekday;
use App\Models\Booking;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\BookingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * TZ-2A / TZ-AUD-016 — CHARACTERIZATION, not new policy.
 *
 * Recurring bookings currently anchor to the STUDENT'S LOCAL WALL-CLOCK
 * time: "Monday 19:00" stays 19:00 for the student across a DST
 * transition, and the underlying UTC instant shifts by an hour instead.
 *
 * That is almost certainly what a human scheduling lessons expects, but
 * the audit found it is achieved INCIDENTALLY — it works only because
 * `BookingWizardService` parses the wall-clock string in the student's
 * timezone and `RecurrenceData::nextStartsAt()` does its calendar
 * arithmetic on that still-zoned instance, with `CreateBookingData`
 * normalizing each occurrence to UTC afterwards. Move that `->utc()`
 * one step earlier and the platform silently becomes a fixed-interval
 * scheduler: every lesson after a transition drifts an hour in the
 * student's own clock, and nothing fails.
 *
 * These tests pin the ordering. They assert what the code does TODAY.
 * They do not decide the open product questions (recurrence anchor,
 * nonexistent/ambiguous local times, mismatched student/instructor DST
 * dates) — those are flagged, not answered.
 */
class RecurringBookingDstCharacterizationTest extends TestCase
{
    use CreatesStudentLessonPrices, RefreshDatabase;

    private array $priced;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $this->priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR');
        $this->seedLessonSubject('maths');

        // Recurrence, not capacity, is what these tests measure.
        $settings = app(BookingSettings::class);
        $settings->max_daily_bookings_per_teacher = null;
        $settings->maximum_advance_booking_days = 3650;
        $settings->save();
    }

    private function instructor(string $timezone): User
    {
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $teacher->id], [
            'instructor_status' => 'approved',
            'profile_visibility' => 'public',
            'timezone' => $timezone,
        ]);
        TeacherSubject::factory()->state(['teacher_id' => $teacher->id])->subject('maths', 1, 12)->create();

        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()
                ->state(['teacher_id' => $teacher->id, 'timezone' => $timezone])
                ->forDay($day)
                ->between('00:00:00', '23:00:00')
                ->create();
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

    /**
     * Books a real recurring series through the production service and
     * returns each persisted occurrence rendered back in the student's
     * timezone, ordered.
     *
     * @return list<CarbonImmutable>
     */
    private function bookSeriesLocalTimes(
        User $student,
        User $teacher,
        string $firstLocalStart,
        string $timezone,
        int $occurrences,
    ): array {
        Auth::login($student);

        $result = app(WizardBookingServiceInterface::class)->bookRecurring(
            new WizardBookingData(
                typeKey: 'paid_one_to_one',
                subject: 'maths',
                grade: 8,
                // Exactly what the wizard does: parse the student's
                // wall-clock selection IN THEIR timezone.
                startsAt: CarbonImmutable::parse($firstLocalStart, $timezone),
                timezone: $timezone,
                teacherId: $teacher->id,
            ),
            new RecurrenceData(occurrences: $occurrences, frequency: RecurrenceFrequency::Weekly),
        );

        $this->assertSame([], $result->failures, 'every occurrence must be bookable in these fixtures');

        return Booking::query()
            ->whereIn('id', $result->booked->pluck('id'))
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Booking $b): CarbonImmutable => $b->starts_at->setTimezone($timezone))
            ->all();
    }

    // ── Spring forward ──────────────────────────────────────────────────

    /**
     * Europe/London springs forward on 2027-03-28 (GMT → BST). A weekly
     * Monday 19:00 series starting 2027-03-22 crosses it on the second
     * occurrence.
     */
    public function test_weekly_series_keeps_the_student_local_time_across_spring_forward(): void
    {
        $timezone = 'Europe/London';
        $student = $this->student($timezone);
        $teacher = $this->instructor($timezone);

        $starts = $this->bookSeriesLocalTimes($student, $teacher, '2027-03-22 19:00', $timezone, 3);

        foreach ($starts as $index => $start) {
            $this->assertSame('19:00', $start->format('H:i'), "occurrence {$index} drifted in the student's clock");
            $this->assertSame(1, $start->dayOfWeek, 'every occurrence stays a Monday');
        }

        // The wall clock held still, so the absolute instant moved.
        $this->assertSame('2027-03-22T19:00:00+00:00', $starts[0]->toIso8601String());
        $this->assertSame('2027-03-29T19:00:00+01:00', $starts[1]->toIso8601String());

        $this->assertSame('19:00', $starts[0]->utc()->format('H:i'), 'before DST: 19:00 UTC');
        $this->assertSame('18:00', $starts[1]->utc()->format('H:i'), 'after DST: 18:00 UTC — one hour earlier');

        // Consecutive occurrences are 7 calendar days but NOT 168 hours.
        $this->assertSame(167.0, $starts[0]->diffInHours($starts[1]));
    }

    // ── Fall back ───────────────────────────────────────────────────────

    /** America/New_York falls back on 2026-11-01 (EDT → EST). */
    public function test_weekly_series_keeps_the_student_local_time_across_fall_back(): void
    {
        $timezone = 'America/New_York';
        $student = $this->student($timezone);
        $teacher = $this->instructor($timezone);

        $starts = $this->bookSeriesLocalTimes($student, $teacher, '2026-10-28 19:00', $timezone, 3);

        foreach ($starts as $index => $start) {
            $this->assertSame('19:00', $start->format('H:i'), "occurrence {$index} drifted in the student's clock");
        }

        $this->assertSame('2026-10-28T19:00:00-04:00', $starts[0]->toIso8601String());
        $this->assertSame('2026-11-04T19:00:00-05:00', $starts[1]->toIso8601String());

        $this->assertSame('23:00', $starts[0]->utc()->format('H:i'), 'before DST: 23:00 UTC');
        $this->assertSame('00:00', $starts[1]->utc()->format('H:i'), 'after DST: 00:00 UTC — one hour later');

        // 169 hours: the fall-back week is a day plus an hour longer.
        $this->assertSame(169.0, $starts[0]->diffInHours($starts[1]));
    }

    // ── The ordering this protects ──────────────────────────────────────

    /**
     * The guard against a "tidy-up" refactor. Occurrences are generated
     * on the ZONED instant and normalized to UTC only afterwards; doing
     * it the other way round produces a fixed 168-hour interval, which
     * this asserts is NOT what happens.
     */
    public function test_recurrence_is_generated_before_utc_normalization_not_after(): void
    {
        $timezone = 'Europe/London';
        $first = CarbonImmutable::parse('2026-03-23 19:00', $timezone);
        $recurrence = new RecurrenceData(occurrences: 2, frequency: RecurrenceFrequency::Weekly);

        // Current (correct) ordering: expand locally, then convert.
        $localFirst = $recurrence->nextStartsAt($first, 1);
        $this->assertSame('19:00', $localFirst->format('H:i'));
        $this->assertSame('18:00', $localFirst->utc()->format('H:i'));

        // The refactor to guard against: convert first, then expand.
        $utcFirst = $recurrence->nextStartsAt($first->utc(), 1);
        $this->assertSame('19:00', $utcFirst->format('H:i'), 'UTC-first arithmetic holds the UTC clock still');
        $this->assertSame(
            '20:00',
            $utcFirst->setTimezone($timezone)->format('H:i'),
            'and therefore drags the student to 20:00 — the bug this ordering prevents',
        );

        $this->assertFalse(
            $localFirst->equalTo($utcFirst),
            'The two orderings differ across DST. If this ever passes, recurrence semantics silently changed.',
        );
    }

    // ── Open product questions — observed, deliberately NOT decided ─────

    /**
     * PRODUCT DECISION PENDING — nonexistent and ambiguous local times.
     *
     * Europe/London 2026-03-29 01:30 does not exist (clocks jump
     * 01:00 → 02:00); America/New_York 2026-11-01 01:30 happens twice.
     * PHP resolves both silently, with no error and no way for a caller
     * to know it happened.
     *
     * This records what PHP does. It asserts NO business rule, because
     * none has been approved: whether such an occurrence should be
     * skipped, shifted, or refused at booking time is an open question
     * for TZ-2B/TZ-6, not something to settle inside a bug fix.
     */
    public function test_observed_behaviour_for_nonexistent_and_ambiguous_local_times(): void
    {
        $nonexistent = CarbonImmutable::parse('2026-03-29 01:30', 'Europe/London');

        // PHP shifts a skipped wall-clock time forward by the DST offset.
        $this->assertSame('02:30', $nonexistent->format('H:i'));
        $this->assertSame('01:30', $nonexistent->utc()->format('H:i'));

        $ambiguous = CarbonImmutable::parse('2026-11-01 01:30', 'America/New_York');

        // PHP picks the FIRST (still-EDT) of the two 01:30s.
        $this->assertSame('01:30', $ambiguous->format('H:i'));
        $this->assertSame('-04:00', $ambiguous->format('P'));
        $this->assertSame('05:30', $ambiguous->utc()->format('H:i'));

        $this->markTestIncomplete(
            'Characterization only. No approved policy exists for nonexistent or ambiguous '.
            'recurring local times; the assertions above document PHP/Carbon behaviour and '.
            'must not be read as a business requirement. Product decision required before TZ-2B/TZ-6.'
        );
    }

    /**
     * PRODUCT DECISION PENDING — student and instructor whose DST
     * transitions fall on different dates.
     *
     * Recurrence anchors to the STUDENT's wall clock. In 2026 the US
     * springs forward on 8 March and the UK on 29 March, so for the
     * three weeks between them a New York student's steady 09:00 series
     * moves by an hour in a London instructor's calendar.
     *
     * Evidence only. TZ-2A does not "correct" this to instructor-local
     * recurrence — that is Product Decision 1 from the audit.
     */
    public function test_observed_instructor_side_drift_when_dst_dates_differ(): void
    {
        $studentZone = 'America/New_York';
        $instructorZone = 'Europe/London';
        $recurrence = new RecurrenceData(occurrences: 3, frequency: RecurrenceFrequency::Weekly);

        // Sunday 1 March 2026: both zones still on winter time.
        $first = CarbonImmutable::parse('2026-03-01 09:00', $studentZone);

        $studentTimes = [];
        $instructorTimes = [];

        foreach (range(0, 2) as $index) {
            $occurrence = $recurrence->nextStartsAt($first, $index);
            $studentTimes[] = $occurrence->format('H:i');
            $instructorTimes[] = $occurrence->setTimezone($instructorZone)->format('H:i');
        }

        // The student's clock is rock steady — the anchor works.
        $this->assertSame(['09:00', '09:00', '09:00'], $studentTimes);

        // The instructor's is not, for exactly the weeks the offsets differ.
        $this->assertSame(['14:00', '13:00', '13:00'], $instructorTimes);

        $this->markTestIncomplete(
            'Characterization only. Whether a series should hold the student\'s or the '.
            'instructor\'s wall clock when their DST dates differ is Product Decision 1 '.
            'from the timezone audit and is not settled by TZ-2A.'
        );
    }
}
