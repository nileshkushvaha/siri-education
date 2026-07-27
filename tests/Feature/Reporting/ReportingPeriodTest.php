<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Exceptions\InvalidReportingPeriodException;
use App\Reporting\Exceptions\InvalidReportingTimezoneException;
use App\Reporting\ValueObjects\ReportingPeriod;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The shared reporting-period value object: timezone-
 * safe UTC boundary conversion, presets, custom ranges, and the
 * half-open `[start_utc, end_utc_exclusive)` query interval (never a
 * fragile `23:59:59` end-of-day calculation).
 */
class ReportingPeriodTest extends TestCase
{
    // ── Presets ──────────────────────────────────────────────────────────

    public function test_today_preset_spans_one_local_calendar_day(): void
    {
        $now = CarbonImmutable::parse('2026-03-15 10:00:00', 'Asia/Kolkata');
        $period = ReportingPeriod::forPreset(ReportingPeriodPreset::Today, 'Asia/Kolkata', $now);

        $this->assertSame('2026-03-15', $period->start->toDateString());
        $this->assertSame('2026-03-16', $period->end->toDateString());
        $this->assertSame('Asia/Kolkata', $period->timezone);
    }

    public function test_yesterday_preset(): void
    {
        $now = CarbonImmutable::parse('2026-03-15 10:00:00', 'Asia/Kolkata');
        $period = ReportingPeriod::forPreset(ReportingPeriodPreset::Yesterday, 'Asia/Kolkata', $now);

        $this->assertSame('2026-03-14', $period->start->toDateString());
        $this->assertSame('2026-03-15', $period->end->toDateString());
    }

    public function test_last_7_days_spans_seven_calendar_days_including_today(): void
    {
        $now = CarbonImmutable::parse('2026-03-15 10:00:00', 'Asia/Kolkata');
        $period = ReportingPeriod::forPreset(ReportingPeriodPreset::Last7Days, 'Asia/Kolkata', $now);

        $this->assertSame('2026-03-09', $period->start->toDateString());
        $this->assertSame('2026-03-16', $period->end->toDateString());
        $this->assertSame(7, (int) $period->start->diffInDays($period->end));
    }

    public function test_last_30_days_spans_thirty_calendar_days(): void
    {
        $now = CarbonImmutable::parse('2026-03-15 10:00:00', 'Asia/Kolkata');
        $period = ReportingPeriod::forPreset(ReportingPeriodPreset::Last30Days, 'Asia/Kolkata', $now);

        $this->assertSame(30, (int) $period->start->diffInDays($period->end));
    }

    public function test_this_month_preset(): void
    {
        $now = CarbonImmutable::parse('2026-03-15 10:00:00', 'Asia/Kolkata');
        $period = ReportingPeriod::forPreset(ReportingPeriodPreset::ThisMonth, 'Asia/Kolkata', $now);

        $this->assertSame('2026-03-01', $period->start->toDateString());
        $this->assertSame('2026-04-01', $period->end->toDateString());
    }

    public function test_previous_month_preset(): void
    {
        $now = CarbonImmutable::parse('2026-03-15 10:00:00', 'Asia/Kolkata');
        $period = ReportingPeriod::forPreset(ReportingPeriodPreset::PreviousMonth, 'Asia/Kolkata', $now);

        $this->assertSame('2026-02-01', $period->start->toDateString());
        $this->assertSame('2026-03-01', $period->end->toDateString());
    }

    public function test_previous_month_preset_across_year_boundary(): void
    {
        $now = CarbonImmutable::parse('2026-01-10 10:00:00', 'UTC');
        $period = ReportingPeriod::forPreset(ReportingPeriodPreset::PreviousMonth, 'UTC', $now);

        $this->assertSame('2025-12-01', $period->start->toDateString());
        $this->assertSame('2026-01-01', $period->end->toDateString());
    }

    // ── Custom range ─────────────────────────────────────────────────────

    public function test_custom_range_with_identical_start_and_end_spans_one_full_local_day(): void
    {
        $period = ReportingPeriod::custom('2026-03-15', '2026-03-15', 'Asia/Kolkata');

        $this->assertSame('2026-03-15', $period->start->toDateString());
        $this->assertSame('2026-03-16', $period->end->toDateString());
        $this->assertSame(1, (int) $period->start->diffInDays($period->end));
    }

    public function test_custom_range_end_before_start_is_rejected(): void
    {
        $this->expectException(InvalidReportingPeriodException::class);

        ReportingPeriod::custom('2026-03-20', '2026-03-10', 'UTC');
    }

    public function test_custom_range_exceeding_maximum_is_rejected(): void
    {
        $this->expectException(InvalidReportingPeriodException::class);

        ReportingPeriod::custom('2020-01-01', '2026-01-01', 'UTC');
    }

    public function test_custom_range_at_exactly_the_maximum_is_accepted(): void
    {
        $start = CarbonImmutable::parse('2026-01-01');
        $end = $start->addDays(ReportingPeriod::MAX_CUSTOM_RANGE_DAYS - 1);

        $period = ReportingPeriod::custom($start, $end, 'UTC');

        $this->assertSame($start->toDateString(), $period->start->toDateString());
    }

    // ── Timezone conversion & UTC boundary ───────────────────────────────

    public function test_asia_kolkata_boundaries_convert_correctly_to_utc(): void
    {
        // IST is UTC+05:30 with no DST — local midnight March 15 is 2026-03-14T18:30:00Z.
        $now = CarbonImmutable::parse('2026-03-15 10:00:00', 'Asia/Kolkata');
        $period = ReportingPeriod::forPreset(ReportingPeriodPreset::Today, 'Asia/Kolkata', $now);

        $this->assertSame('2026-03-14T18:30:00+00:00', $period->startUtc->toIso8601String());
        $this->assertSame('2026-03-15T18:30:00+00:00', $period->endUtcExclusive->toIso8601String());
    }

    public function test_utc_timezone_boundaries_are_identical_to_local(): void
    {
        $now = CarbonImmutable::parse('2026-03-15 10:00:00', 'UTC');
        $period = ReportingPeriod::forPreset(ReportingPeriodPreset::Today, 'UTC', $now);

        $this->assertSame('2026-03-15T00:00:00+00:00', $period->startUtc->toIso8601String());
        $this->assertSame('2026-03-16T00:00:00+00:00', $period->endUtcExclusive->toIso8601String());
    }

    public function test_exclusive_end_boundary_has_no_23_59_59_dependency(): void
    {
        $period = ReportingPeriod::forPreset(ReportingPeriodPreset::Today, 'UTC', CarbonImmutable::parse('2026-03-15 10:00:00'));

        // The exclusive boundary is exactly the next midnight instant, not 23:59:59.
        $this->assertSame('00:00:00', $period->endUtcExclusive->toTimeString());
    }

    // ── Daylight-saving transitions (America/New_York) ───────────────────

    public function test_daylight_saving_spring_forward_transition_produces_a_23_hour_local_day(): void
    {
        // US spring-forward 2026: clocks jump forward at 2am on 2026-03-08.
        $now = CarbonImmutable::parse('2026-03-08 12:00:00', 'America/New_York');
        $period = ReportingPeriod::forPreset(ReportingPeriodPreset::Today, 'America/New_York', $now);

        $this->assertSame(23, (int) $period->startUtc->diffInHours($period->endUtcExclusive));
    }

    public function test_daylight_saving_autumn_transition_produces_a_25_hour_local_day(): void
    {
        // US fall-back 2026: clocks fall back at 2am on 2026-11-01.
        $now = CarbonImmutable::parse('2026-11-01 12:00:00', 'America/New_York');
        $period = ReportingPeriod::forPreset(ReportingPeriodPreset::Today, 'America/New_York', $now);

        $this->assertSame(25, (int) $period->startUtc->diffInHours($period->endUtcExclusive));
    }

    // ── Invalid timezone ─────────────────────────────────────────────────

    public function test_invalid_explicit_timezone_is_rejected(): void
    {
        $this->expectException(InvalidReportingTimezoneException::class);

        ReportingPeriod::forPreset(ReportingPeriodPreset::Today, 'Mars/Phobos');
    }

    public function test_custom_preset_requires_explicit_start_end_via_custom_factory(): void
    {
        $this->expectException(InvalidReportingPeriodException::class);

        ReportingPeriod::forPreset(ReportingPeriodPreset::Custom, 'UTC');
    }
}
