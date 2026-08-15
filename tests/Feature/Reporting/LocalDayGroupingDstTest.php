<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Models\User;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Repositories\StudentEngagementRepository;
use App\Reporting\Support\LocalDaySql;
use App\Reporting\ValueObjects\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TZ-5 / TZ-AUD-010 — daily reporting buckets are DST-exact.
 *
 * The old implementation froze the reporting timezone's offset at the
 * START of the period and reused it for every row. Inside a period that
 * crosses a DST transition that offset is an hour stale afterwards, so
 * rows near local midnight were counted on the wrong day — silently, in
 * a number people read as fact.
 *
 * Every fixture here is placed deliberately within an hour of local
 * midnight ON THE FAR SIDE of a transition, which is exactly where the
 * two implementations disagree. A fixture at midday would pass either
 * way and prove nothing.
 */
class LocalDayGroupingDstTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
    }

    private function studentRegisteredAt(string $utc): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('student');
        // created_at is guarded by timestamps; write it directly.
        DB::table('users')->where('id', $user->id)->update(['created_at' => $utc]);

        return $user;
    }

    /** @return array<string, int> */
    private function trend(ReportingPeriod $period): array
    {
        return app(StudentEngagementRepository::class)->registrationTrend($period, new ReportFilters($period));
    }

    /** What the OLD code did: one offset, captured at period start, applied to everything. */
    private function frozenOffsetDay(string $utc, ReportingPeriod $period): string
    {
        return CarbonImmutable::parse($utc, 'UTC')
            ->addSeconds($period->startUtc->setTimezone($period->timezone)->getOffset())
            ->format('Y-m-d');
    }

    // ── Spring forward ──────────────────────────────────────────────────

    public function test_spring_forward_rows_land_on_the_correct_local_day(): void
    {
        // Europe/London goes GMT -> BST at 01:00 UTC on 2027-03-28.
        $period = ReportingPeriod::custom('2027-03-26', '2027-03-30', 'Europe/London');

        // 23:30 UTC on the 29th is 00:30 BST on the 30th — a different
        // day. Under the frozen +00:00 offset it was counted on the 29th.
        $afterTransition = '2027-03-29 23:30:00';
        $this->studentRegisteredAt($afterTransition);

        $trend = $this->trend($period);

        $this->assertSame(1, $trend['2027-03-30'], 'must be counted on the local day it actually falls on');
        $this->assertSame(0, $trend['2027-03-29']);

        // Proof the fixture discriminates: the old logic disagreed.
        $this->assertSame('2027-03-29', $this->frozenOffsetDay($afterTransition, $period));
    }

    public function test_rows_either_side_of_the_spring_transition_are_separated(): void
    {
        $period = ReportingPeriod::custom('2027-03-26', '2027-03-30', 'Europe/London');

        $this->studentRegisteredAt('2027-03-27 23:30:00'); // 23:30 GMT, still the 27th
        $this->studentRegisteredAt('2027-03-29 23:30:00'); // 00:30 BST, the 30th

        $trend = $this->trend($period);

        $this->assertSame(1, $trend['2027-03-27']);
        $this->assertSame(1, $trend['2027-03-30']);
        $this->assertSame(0, $trend['2027-03-28']);
        $this->assertSame(2, array_sum($trend), 'no row may be lost or double-counted');
    }

    // ── Fall back ───────────────────────────────────────────────────────

    public function test_fall_back_rows_land_on_the_correct_local_day(): void
    {
        // America/New_York goes EDT (-4) -> EST (-5) at 06:00 UTC on
        // 2026-11-01.
        $period = ReportingPeriod::custom('2026-10-30', '2026-11-03', 'America/New_York');

        // 04:30 UTC on the 2nd is 23:30 EST on the 1st. Under the frozen
        // -04:00 offset it was counted on the 2nd.
        $afterTransition = '2026-11-02 04:30:00';
        $this->studentRegisteredAt($afterTransition);

        $trend = $this->trend($period);

        $this->assertSame(1, $trend['2026-11-01']);
        $this->assertSame(0, $trend['2026-11-02']);
        $this->assertSame('2026-11-02', $this->frozenOffsetDay($afterTransition, $period));
    }

    // ── Half-hour offset ────────────────────────────────────────────────

    public function test_a_half_hour_offset_zone_buckets_exactly(): void
    {
        // Asia/Kolkata is +05:30 year-round — the case an implementation
        // that assumed whole hours would get wrong.
        $period = ReportingPeriod::custom('2026-08-14', '2026-08-16', 'Asia/Kolkata');

        $this->studentRegisteredAt('2026-08-14 18:29:00'); // 23:59 IST on the 14th
        $this->studentRegisteredAt('2026-08-14 18:31:00'); // 00:01 IST on the 15th

        $trend = $this->trend($period);

        $this->assertSame(1, $trend['2026-08-14']);
        $this->assertSame(1, $trend['2026-08-15']);
    }

    // ── The generated SQL ───────────────────────────────────────────────

    public function test_a_fixed_offset_period_needs_no_case_expression(): void
    {
        [$sql, $bindings] = LocalDaySql::dateExpression('users.created_at', ReportingPeriod::custom('2026-08-14', '2026-08-16', 'Asia/Kolkata'));

        $this->assertStringNotContainsString('CASE', $sql);
        $this->assertStringContainsString('INTERVAL 19800 SECOND', $sql); // +05:30
        $this->assertSame([], $bindings);
    }

    public function test_a_transition_period_emits_one_bounded_case_not_one_branch_per_day(): void
    {
        // A full year across two transitions must still produce a small,
        // constant-size expression — never a WHEN per day.
        [$sql, $bindings] = LocalDaySql::dateExpression('users.created_at', ReportingPeriod::custom('2027-01-01', '2027-12-31', 'Europe/London'));

        $this->assertStringContainsString('CASE', $sql);
        $this->assertSame(2, substr_count($sql, 'WHEN'), 'two transitions in 2027 => two WHEN branches');
        $this->assertCount(2, $bindings);
        $this->assertStringContainsString('INTERVAL 0 SECOND', $sql);
        $this->assertStringContainsString('INTERVAL 3600 SECOND', $sql);
    }

    public function test_named_zone_convert_tz_is_deliberately_not_used(): void
    {
        // It would return NULL here: mysql.time_zone_name is empty on
        // this deployment, so a report would silently produce no rows.
        // This pins WHY the PHP/IANA approach exists.
        $this->assertNull(DB::selectOne('SELECT CONVERT_TZ("2026-08-15 12:00:00","UTC","Europe/London") v')->v);
    }
}
