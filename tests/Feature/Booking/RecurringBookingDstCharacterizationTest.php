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
 * TZ-2A / TZ-AUD-016 — SUPERSEDED BY TZ-6. Kept as the historical record
 * of what the platform did BEFORE the recurrence anchor was decided, not
 * as a statement of current behaviour.
 *
 * TZ-6 closed the open product question the other way: a series is now
 * anchored to the INSTRUCTOR'S availability timezone, because the weekly
 * rule belongs to the instructor and a student-anchored series walked
 * their teaching slot out of its own availability window whenever the
 * two countries changed clocks on different dates. Current policy and
 * its proofs live in TimezonePolicyClosureTest.
 *
 * The tests below therefore exercise RecurrenceData in ISOLATION — the
 * arithmetic primitive, given an explicitly zoned anchor — which is
 * unchanged and still the mechanism the new policy relies on. What
 * changed is WHICH timezone the service hands it. The two
 * production-path tests that asserted student-local anchoring have moved
 * to TimezonePolicyClosureTest in their corrected form.
 *
 * Original note, retained for context — recurring bookings USED TO
 * anchor to the STUDENT'S LOCAL WALL-CLOCK time: "Monday 19:00" stays 19:00 for the student across a DST
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
     * Books a real recurring series through the production service.
     *
     * Retained for the ordering guard below; the anchor-policy
     * assertions it used to serve now live in TimezonePolicyClosureTest.
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

    // ── Fall back ───────────────────────────────────────────────────────

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
