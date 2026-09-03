<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\AvailabilityServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\DTOs\AvailabilityQueryData;
use App\Booking\DTOs\TimeSlotData;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Holiday;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\BookingSettings;
use App\Support\Timezone\LocalDay;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TZ-2A — TZ-AUD-005 and TZ-AUD-006.
 *
 * Both defects had the same shape: `$utcInstant->toDateString()` used as
 * if it were the instructor's calendar date. It is not. An instructor in
 * Australia/Sydney (UTC+10/+11) sees their morning carry the PREVIOUS
 * UTC date; one in America/Los_Angeles (UTC-7/-8) sees their evening
 * carry the NEXT one. So holidays were applied to the wrong day in both
 * directions, and a single instructor day's bookings were split across
 * two daily-cap buckets.
 *
 * Every test here is written so it FAILS against the old
 * implementation — the local date and the UTC date are always
 * deliberately different. A test where they coincide proves nothing.
 */
class AvailabilityLocalDayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every fixture here is anchored to one fixed week whose local and
        // UTC dates deliberately straddle (see the class docblock) — the
        // offsets quoted in the comments are August ones. Once that week
        // fell into the past the availability query correctly returned
        // nothing, so the "must be empty" tests passed for the wrong
        // reason and the "must not be empty" ones failed for a reason
        // unrelated to local-day handling. Freeze the clock just before
        // it so the file keeps testing what it says it tests.
        $this->travelTo(CarbonImmutable::parse('2026-08-10 00:00:00', 'UTC'));

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        BookingType::factory()->create([
            'key' => 'free_demo', 'name' => 'Free Demo',
            'duration_minutes' => 60, 'buffer_minutes' => 0, 'sort_order' => 1,
        ]);
    }

    /** An instructor whose availability rules are authored in $timezone, bookable all day every day. */
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

    private function student(): User
    {
        return User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
    }

    private function maxDaily(?int $limit): void
    {
        $settings = app(BookingSettings::class);
        $settings->max_daily_bookings_per_teacher = $limit;
        $settings->save();
    }

    /** A confirmed booking at an exact local wall-clock time in $timezone. */
    private function bookingAt(User $teacher, string $localDateTime, string $timezone): Booking
    {
        $startsAt = CarbonImmutable::parse($localDateTime, $timezone)->utc();

        return Booking::factory()->create([
            'instructor_id' => $teacher->id,
            'student_id' => $this->student()->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'timezone' => $timezone,
        ]);
    }

    /** @return Collection<int, TimeSlotData> */
    private function slotsOnLocalDate(User $teacher, string $localDate, string $timezone): Collection
    {
        $day = LocalDay::of($localDate, $timezone);

        return app(AvailabilityServiceInterface::class)
            ->slots(new AvailabilityQueryData(
                instructorId: $teacher->id,
                typeKey: 'free_demo',
                from: $day->startUtc,
                to: $day->endUtcExclusive,
                timezone: $timezone,
            ))
            // The service is asked for a UTC range, which can spill into a
            // neighbouring local day; keep only what really belongs to the
            // local date under test.
            ->filter(fn ($slot): bool => $day->contains($slot->startsAt->utc()))
            ->values();
    }

    // ── TZ-AUD-005 · Holiday uses the instructor's calendar date ────────

    /**
     * Sydney is UTC+10, so 08:00 local on the 17th is 22:00 UTC on the
     * 16th. Under the old code the slot's "date" was the 16th, the
     * holiday on the 17th never matched, and the instructor was offered
     * work on their day off.
     */
    public function test_holiday_blocks_slots_whose_local_date_differs_from_their_utc_date(): void
    {
        $timezone = 'Australia/Sydney';
        $teacher = $this->instructor($timezone);
        Holiday::factory()->create(['date' => '2026-08-17', 'name' => 'Local holiday']);

        $morning = CarbonImmutable::parse('2026-08-17 08:00', $timezone);
        $this->assertSame('2026-08-16', $morning->utc()->toDateString(), 'fixture must straddle the UTC date');

        $slots = $this->slotsOnLocalDate($teacher, '2026-08-17', $timezone);

        $this->assertTrue($slots->isEmpty(), 'No slot may be offered on the instructor-local holiday.');
    }

    /**
     * The mirror image, and the reason a one-line "just subtract a day"
     * fix would be wrong: in Los Angeles (UTC-7) an evening slot on the
     * 16th carries the 17th in UTC, so the old code blocked the WRONG
     * day — a perfectly ordinary working evening disappeared.
     */
    public function test_a_holiday_does_not_leak_into_the_adjacent_local_day(): void
    {
        $timezone = 'America/Los_Angeles';
        $teacher = $this->instructor($timezone);
        Holiday::factory()->create(['date' => '2026-08-17', 'name' => 'Local holiday']);

        $evening = CarbonImmutable::parse('2026-08-16 20:00', $timezone);
        $this->assertSame('2026-08-17', $evening->utc()->toDateString(), 'fixture must straddle the UTC date');

        $slots = $this->slotsOnLocalDate($teacher, '2026-08-16', $timezone);

        $this->assertTrue($slots->isNotEmpty(), 'The day before a holiday is a normal working day.');
        $this->assertTrue(
            $slots->contains(fn ($slot): bool => $slot->startsAt->format('H:i') === '20:00'),
            'The 20:00 local slot carries the holiday UTC date but is not on the holiday.',
        );
    }

    public function test_holiday_blocks_the_whole_local_day_when_local_and_utc_dates_coincide(): void
    {
        $teacher = $this->instructor('UTC');
        Holiday::factory()->create(['date' => '2026-08-17', 'name' => 'Local holiday']);

        $this->assertTrue($this->slotsOnLocalDate($teacher, '2026-08-17', 'UTC')->isEmpty());
        $this->assertTrue($this->slotsOnLocalDate($teacher, '2026-08-18', 'UTC')->isNotEmpty());
    }

    public function test_holiday_is_enforced_on_the_booking_path_not_only_when_listing_slots(): void
    {
        // slots() offering and ensureAvailable() enforcing must agree
        // about which day a slot is on, or a student is shown a slot
        // that explodes at submit (or books one they were never offered).
        $timezone = 'Asia/Kolkata';
        $teacher = $this->instructor($timezone);
        Holiday::factory()->create(['date' => '2026-08-17', 'name' => 'Local holiday']);

        $earlyMorning = CarbonImmutable::parse('2026-08-17 03:00', $timezone);
        $this->assertSame('2026-08-16', $earlyMorning->utc()->toDateString());

        $this->expectException(SlotUnavailableException::class);

        app(AvailabilityServiceInterface::class)->ensureAvailable(
            $teacher->id,
            $earlyMorning->utc(),
            $earlyMorning->addHour()->utc(),
        );
    }

    // ── TZ-AUD-006 · Daily cap counts the instructor's local day ────────

    /**
     * Case A. Kolkata is UTC+5:30. 02:00 and 22:00 on the 17th local are
     * the 16th and the 17th in UTC respectively — one instructor day,
     * two UTC dates. The old bucketing counted them as two separate days
     * and let the instructor exceed their cap.
     */
    public function test_two_bookings_on_one_local_day_share_a_cap_bucket_even_across_two_utc_dates(): void
    {
        $timezone = 'Asia/Kolkata';
        $teacher = $this->instructor($timezone);

        $early = $this->bookingAt($teacher, '2026-08-17 02:00', $timezone);
        $late = $this->bookingAt($teacher, '2026-08-17 22:00', $timezone);

        $this->assertNotSame(
            $early->starts_at->utc()->toDateString(),
            $late->starts_at->utc()->toDateString(),
            'fixture must span two UTC dates',
        );

        $count = app(BookingRepositoryInterface::class)
            ->activeCountForDay($teacher->id, LocalDay::of('2026-08-17', $timezone));

        $this->assertSame(2, $count);
    }

    /**
     * Case B, the converse. Two bookings sharing a UTC date but sitting
     * on two different instructor days must never share a bucket — the
     * old code merged them and blocked a day that was actually free.
     */
    public function test_two_bookings_on_one_utc_date_but_different_local_days_are_counted_separately(): void
    {
        $timezone = 'Asia/Kolkata';
        $teacher = $this->instructor($timezone);

        // Both are 2026-08-17 in UTC: 05:35 IST is 00:05 UTC on the 17th,
        // and 2026-08-18 03:00 IST is 21:30 UTC on the 17th.
        $first = $this->bookingAt($teacher, '2026-08-17 05:35', $timezone);
        $second = $this->bookingAt($teacher, '2026-08-18 03:00', $timezone);

        $this->assertSame('2026-08-17', $first->starts_at->utc()->toDateString());
        $this->assertSame('2026-08-17', $second->starts_at->utc()->toDateString());

        $repository = app(BookingRepositoryInterface::class);

        $this->assertSame(1, $repository->activeCountForDay($teacher->id, LocalDay::of('2026-08-17', $timezone)));
        $this->assertSame(1, $repository->activeCountForDay($teacher->id, LocalDay::of('2026-08-18', $timezone)));
    }

    /** Case D — a reached cap removes that local day's slots, and only that day's. */
    public function test_a_reached_cap_clears_the_local_day_but_leaves_the_next_day_bookable(): void
    {
        $timezone = 'Australia/Sydney';
        $teacher = $this->instructor($timezone);
        $this->maxDaily(2);

        // Straddles the UTC date boundary within one Sydney day.
        $this->bookingAt($teacher, '2026-08-17 08:00', $timezone);
        $this->bookingAt($teacher, '2026-08-17 20:00', $timezone);

        $this->assertTrue(
            $this->slotsOnLocalDate($teacher, '2026-08-17', $timezone)->isEmpty(),
            'The cap is reached for this instructor-local day.',
        );
        $this->assertTrue(
            $this->slotsOnLocalDate($teacher, '2026-08-18', $timezone)->isNotEmpty(),
            'The next local day has its own budget.',
        );
    }

    public function test_the_cap_is_enforced_on_the_booking_path_using_the_local_day(): void
    {
        $timezone = 'Asia/Kolkata';
        $teacher = $this->instructor($timezone);
        $this->maxDaily(2);

        $this->bookingAt($teacher, '2026-08-17 02:00', $timezone);
        $this->bookingAt($teacher, '2026-08-17 22:00', $timezone);

        $third = CarbonImmutable::parse('2026-08-17 12:00', $timezone);

        $this->expectException(SlotUnavailableException::class);

        app(AvailabilityServiceInterface::class)
            ->ensureAvailable($teacher->id, $third->utc(), $third->addHour()->utc());
    }

    // ── Case C · DST-safe local-day boundaries ──────────────────────────

    /**
     * Europe/London springs forward on 2026-03-29: the local day is 23
     * hours long. A boundary built as `startUtc + 86400s` would reach an
     * hour into the 30th and count a booking from the wrong day.
     */
    public function test_a_short_spring_forward_local_day_has_exact_boundaries(): void
    {
        $timezone = 'Europe/London';
        $day = LocalDay::of('2026-03-29', $timezone);

        $this->assertSame(23.0, $day->startUtc->diffInHours($day->endUtcExclusive));
        $this->assertSame('2026-03-29T00:00:00+00:00', $day->startUtc->toIso8601String());
        $this->assertSame('2026-03-29T23:00:00+00:00', $day->endUtcExclusive->toIso8601String());
    }

    /** America/New_York falls back on 2026-11-01: a 25-hour local day. */
    public function test_a_long_fall_back_local_day_has_exact_boundaries(): void
    {
        $timezone = 'America/New_York';
        $day = LocalDay::of('2026-11-01', $timezone);

        $this->assertSame(25.0, $day->startUtc->diffInHours($day->endUtcExclusive));
    }

    public function test_the_cap_counts_correctly_across_a_dst_transition_day(): void
    {
        $timezone = 'Europe/London';
        $teacher = $this->instructor($timezone);

        // 00:30 GMT and 22:00 BST — both on the 23-hour local day, either
        // side of the 01:00 → 02:00 jump.
        $this->bookingAt($teacher, '2026-03-29 00:30', $timezone);
        $this->bookingAt($teacher, '2026-03-29 22:00', $timezone);
        // The following local day must stay in its own bucket.
        $this->bookingAt($teacher, '2026-03-30 10:00', $timezone);

        $repository = app(BookingRepositoryInterface::class);

        $this->assertSame(2, $repository->activeCountForDay($teacher->id, LocalDay::of('2026-03-29', $timezone)));
        $this->assertSame(1, $repository->activeCountForDay($teacher->id, LocalDay::of('2026-03-30', $timezone)));
    }

    // ── Evidence the fixtures actually discriminate ─────────────────────

    /**
     * A green test proves nothing unless it would have been red before.
     * The old code cannot be executed here — its signatures are gone —
     * so this reproduces the exact expressions it used against the same
     * fixtures and asserts they give the WRONG answer. If someone later
     * reintroduces `toDateString()` on a UTC instant, the tests above go
     * red and this one explains why.
     */
    public function test_the_old_utc_date_expressions_produce_the_wrong_answer_for_these_fixtures(): void
    {
        $sydney = 'Australia/Sydney';
        $kolkata = 'Asia/Kolkata';

        // TZ-AUD-005: old holiday check was
        // `$holidays->contains($start->toDateString())`, on a UTC $start.
        $morningSlot = CarbonImmutable::parse('2026-08-17 08:00', $sydney)->utc();
        $this->assertSame('2026-08-16', $morningSlot->toDateString(), 'the old expression');
        $this->assertSame('2026-08-17', LocalDay::containing($morningSlot, $sydney)->date, 'the correct answer');
        $this->assertNotSame(
            $morningSlot->toDateString(),
            LocalDay::containing($morningSlot, $sydney)->date,
            'A holiday on 2026-08-17 would not have matched this slot under the old code.',
        );

        // TZ-AUD-006: old cap bucket was
        // `$booking->starts_at->toDateString()`, also on a UTC instant.
        $early = CarbonImmutable::parse('2026-08-17 02:00', $kolkata)->utc();
        $late = CarbonImmutable::parse('2026-08-17 22:00', $kolkata)->utc();

        $this->assertNotSame(
            $early->toDateString(),
            $late->toDateString(),
            'The old code split one instructor day across two buckets.',
        );
        $this->assertSame(
            LocalDay::containing($early, $kolkata)->date,
            LocalDay::containing($late, $kolkata)->date,
            'Both are the same instructor-local day.',
        );

        // And the old UTC-day window really did select a different set:
        // `whereBetween(starts_at, [$day->startOfDay(), $day->endOfDay()])`
        // on a UTC instant spans 00:00–23:59:59 UTC, which for Kolkata
        // begins 5h30m into the local day and ends 5h30m into the next.
        $localDay = LocalDay::of('2026-08-17', $kolkata);
        $oldUtcWindowStart = $localDay->startUtc->startOfDay();

        $this->assertNotEquals($localDay->startUtc, $oldUtcWindowStart);
        $this->assertFalse(
            $localDay->contains(CarbonImmutable::parse('2026-08-17 23:30', 'UTC')),
            'A UTC-day window would have included an instant from the next Kolkata day.',
        );
    }

    // ── Status scope is unchanged by this phase ─────────────────────────

    public function test_the_cap_still_counts_only_active_bookings(): void
    {
        // TZ-2A changes the calendar bucket and nothing else. A cancelled
        // booking was excluded before and must still be excluded.
        $timezone = 'Asia/Kolkata';
        $teacher = $this->instructor($timezone);

        $this->bookingAt($teacher, '2026-08-17 02:00', $timezone);
        $cancelled = $this->bookingAt($teacher, '2026-08-17 20:00', $timezone);
        $cancelled->update(['status' => BookingStatus::Cancelled]);

        $this->assertSame(
            1,
            app(BookingRepositoryInterface::class)
                ->activeCountForDay($teacher->id, LocalDay::of('2026-08-17', $timezone)),
        );
    }
}
