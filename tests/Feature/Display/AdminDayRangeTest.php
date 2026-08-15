<?php

declare(strict_types=1);

namespace Tests\Feature\Display;

use App\Filament\Support\AdminDayRange;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\GeneralSettings;
use App\Support\Timezone\ViewerDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * TZ-5 — the display/reporting split, proved from both sides.
 *
 * OPERATIONAL filters follow the admin looking at the screen, so a
 * filter agrees with the timestamps TZ-4 renders beside it. Two admins
 * in different countries legitimately bucket the same booking under
 * different dates, exactly as two people reading their own calendars
 * would.
 *
 * FINANCIAL periods do not. "Revenue today" must be one number for the
 * whole company; if it followed the viewer, the platform would have as
 * many revenue figures as it has staff.
 *
 * Both are half-open `[startUtc, endUtcExclusive)` ranges built from
 * LocalDay — never `whereDate()` on a UTC column, which is what made a
 * selected date silently mean the UTC day (TZ-AUD-008).
 */
class AdminDayRangeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $timezone): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $admin->id], ['timezone' => $timezone]);

        return $admin->fresh();
    }

    private function reportingTimezone(string $timezone): void
    {
        $settings = app(GeneralSettings::class);
        $settings->default_timezone = $timezone;
        $settings->save();
    }

    private function bookingStartingAt(string $utc): Booking
    {
        $startsAt = CarbonImmutable::parse($utc, 'UTC');

        $type = BookingType::query()->firstOrCreate(
            ['key' => 'free_demo'],
            ['name' => 'Demo', 'duration_minutes' => 60, 'sort_order' => 1],
        );

        return Booking::factory()->create([
            'booking_type_id' => $type->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
        ]);
    }

    // ── Operational filter matches what the admin is shown ──────────────

    public function test_an_operational_filter_uses_the_admins_local_day(): void
    {
        // 20:00 UTC on the 14th is 01:30 on the 15th in Kolkata. A
        // Kolkata admin sees "Aug 15" in the row, so filtering Aug 15
        // must find it — and filtering Aug 14 must not.
        Auth::login($this->admin('Asia/Kolkata'));
        $booking = $this->bookingStartingAt('2026-08-14 20:00:00');

        $onThe15th = Booking::query()
            ->where('starts_at', '>=', AdminDayRange::viewerDay('2026-08-15')->startUtc)
            ->where('starts_at', '<', AdminDayRange::viewerDay('2026-08-15')->endUtcExclusive)
            ->pluck('id');

        $onThe14th = Booking::query()
            ->where('starts_at', '>=', AdminDayRange::viewerDay('2026-08-14')->startUtc)
            ->where('starts_at', '<', AdminDayRange::viewerDay('2026-08-14')->endUtcExclusive)
            ->pluck('id');

        $this->assertTrue($onThe15th->contains($booking->id), 'the admin sees Aug 15, so Aug 15 must match');
        $this->assertFalse($onThe14th->contains($booking->id));
    }

    public function test_the_old_utc_day_filter_would_have_disagreed(): void
    {
        // Evidence the fixture discriminates: whereDate() on the UTC
        // column puts this booking on the 14th, contradicting the 15th
        // the admin is shown.
        Auth::login($this->admin('Asia/Kolkata'));
        $booking = $this->bookingStartingAt('2026-08-14 20:00:00');

        $utcDayMatch = Booking::query()->whereDate('starts_at', '2026-08-15')->pluck('id');

        $this->assertFalse($utcDayMatch->contains($booking->id), 'the old behaviour this phase removed');
    }

    public function test_two_admins_may_bucket_one_operational_record_under_different_dates(): void
    {
        // Acceptable — and correct — for a record list. NOT acceptable
        // for a shared financial total; see below.
        $booking = $this->bookingStartingAt('2026-08-14 20:00:00');

        Auth::login($this->admin('Asia/Kolkata'));
        $kolkataDay = AdminDayRange::viewerDay('2026-08-15');

        Auth::login($this->admin('Europe/London'));
        $londonDay = AdminDayRange::viewerDay('2026-08-14');

        $this->assertTrue($kolkataDay->contains($booking->starts_at->utc()));
        $this->assertTrue($londonDay->contains($booking->starts_at->utc()));
        $this->assertNotEquals($kolkataDay->startUtc, $londonDay->startUtc);
    }

    // ── Financial periods are identical for every admin ─────────────────

    public function test_the_reporting_day_is_the_same_no_matter_who_is_logged_in(): void
    {
        $this->reportingTimezone('Asia/Kolkata');

        Auth::login($this->admin('Europe/London'));
        $asLondonAdmin = AdminDayRange::reportingDay('2026-08-15');

        Auth::login($this->admin('America/Los_Angeles'));
        $asLosAngelesAdmin = AdminDayRange::reportingDay('2026-08-15');

        // THE guarantee: two admins, one revenue figure.
        $this->assertEquals($asLondonAdmin->startUtc, $asLosAngelesAdmin->startUtc);
        $this->assertEquals($asLondonAdmin->endUtcExclusive, $asLosAngelesAdmin->endUtcExclusive);
        $this->assertSame('Asia/Kolkata', $asLondonAdmin->timezone);
        $this->assertSame('Asia/Kolkata', $asLosAngelesAdmin->timezone);
    }

    public function test_reporting_today_does_not_follow_the_viewer(): void
    {
        $this->reportingTimezone('Asia/Kolkata');

        Auth::login($this->admin('America/Los_Angeles'));
        $reporting = AdminDayRange::reportingToday();
        $operational = AdminDayRange::viewerToday();

        $this->assertSame('Asia/Kolkata', $reporting->timezone);
        $this->assertSame('America/Los_Angeles', $operational->timezone);
    }

    public function test_a_financial_total_is_identical_for_two_admins_while_timestamps_differ(): void
    {
        $this->reportingTimezone('Asia/Kolkata');

        // Three bookings spread across one Kolkata day.
        foreach (['2026-08-14 19:00:00', '2026-08-15 06:00:00', '2026-08-15 18:00:00'] as $utc) {
            $this->bookingStartingAt($utc);
        }

        $countFor = function (User $admin): int {
            Auth::login($admin);
            $day = AdminDayRange::reportingDay('2026-08-15');

            return Booking::query()
                ->where('starts_at', '>=', $day->startUtc)
                ->where('starts_at', '<', $day->endUtcExclusive)
                ->count();
        };

        $london = $this->admin('Europe/London');
        $losAngeles = $this->admin('America/Los_Angeles');

        $this->assertSame($countFor($london), $countFor($losAngeles), 'a shared business figure must not depend on who is looking');
        $this->assertSame(3, $countFor($london));

        // …while the same instant still DISPLAYS differently to each.
        $instant = CarbonImmutable::parse('2026-08-14 19:00:00', 'UTC');
        $this->assertNotSame(
            ViewerDateTime::dateTime($instant, $london),
            ViewerDateTime::dateTime($instant, $losAngeles),
        );
    }

    // ── Range boundaries ────────────────────────────────────────────────

    public function test_a_through_date_is_inclusive_and_ends_at_the_next_local_midnight(): void
    {
        Auth::login($this->admin('Asia/Kolkata'));

        // Never 23:59:59 — that loses the final second and is wrong on a
        // DST day.
        $this->assertSame(
            '2026-08-16T00:00:00+05:30',
            AdminDayRange::viewerDay('2026-08-15')->endUtcExclusive->setTimezone('Asia/Kolkata')->toIso8601String(),
        );
    }

    public function test_boundaries_stay_exact_on_a_dst_transition_day(): void
    {
        Auth::login($this->admin('Europe/London'));

        $shortDay = AdminDayRange::viewerDay('2027-03-28');
        $this->assertSame(23.0, $shortDay->startUtc->diffInHours($shortDay->endUtcExclusive));

        Auth::login($this->admin('America/New_York'));
        $longDay = AdminDayRange::viewerDay('2026-11-01');
        $this->assertSame(25.0, $longDay->startUtc->diffInHours($longDay->endUtcExclusive));
    }

    public function test_labels_expose_which_timezone_each_side_uses(): void
    {
        $this->reportingTimezone('Asia/Kolkata');
        Auth::login($this->admin('Europe/London'));

        // An admin must never have to guess whether a filter means their
        // clock or the platform's.
        $this->assertSame('Europe/London', AdminDayRange::viewerLabel());
        $this->assertSame('Asia/Kolkata', AdminDayRange::reportingLabel());
    }
}
