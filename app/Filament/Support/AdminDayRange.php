<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Reporting\Support\ReportingTimezoneResolver;
use App\Support\Timezone\LocalDay;
use App\Support\Timezone\ViewerDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * TZ-5: the two — deliberately different — answers to "which rows fall
 * on this calendar day?" in the admin panel.
 *
 * The distinction is the whole point of this phase, so it lives in one
 * file rather than being decided per table:
 *
 *   OPERATIONAL (`viewerDay`) — a record list an admin is working
 *   through. "Bookings on 15 Aug" must agree with the timestamps
 *   rendered in the next column, which TZ-4 made the admin's own clock.
 *   Two admins in different countries legitimately bucket the same
 *   booking under different dates here, exactly as two people looking at
 *   their own calendars would.
 *
 *   FINANCIAL / REPORTING (`reportingDay`) — a shared business figure.
 *   "Revenue today" must be the SAME number for every admin, or the
 *   platform has as many revenue figures as it has staff. It follows the
 *   configured reporting timezone, never whoever is logged in.
 *
 * Both produce a half-open `[startUtc, endUtcExclusive)` range built
 * from LocalDay, so both are DST-safe and neither uses `whereDate()` on
 * a UTC column — which is what made a selected date mean the UTC day
 * rather than anyone's actual day (TZ-AUD-008).
 */
final class AdminDayRange
{
    /** The logged-in admin's local calendar day — for operational record lists. */
    public static function viewerDay(string $date): LocalDay
    {
        return LocalDay::of($date, ViewerDateTime::timezoneFor());
    }

    /** The platform's reporting calendar day — for shared business figures. */
    public static function reportingDay(string $date): LocalDay
    {
        return LocalDay::of($date, ReportingTimezoneResolver::resolve());
    }

    /** Today in the logged-in admin's clock. */
    public static function viewerToday(): LocalDay
    {
        return LocalDay::containing(CarbonImmutable::now('UTC'), ViewerDateTime::timezoneFor());
    }

    /** Today in the platform's reporting clock — identical for every admin. */
    public static function reportingToday(): LocalDay
    {
        return LocalDay::containing(CarbonImmutable::now('UTC'), ReportingTimezoneResolver::resolve());
    }

    /**
     * Constrain a query to `[from 00:00 local, day-after-through 00:00
     * local)`, in the ADMIN's timezone.
     *
     * Either bound may be null, matching Filament's optional from/until
     * filter inputs. `$through` is INCLUSIVE as the admin reads it — the
     * exclusive boundary is the start of the following local day, never
     * a `23:59:59` that loses the last second and breaks on a DST day.
     */
    public static function constrainViewerRange(Builder $query, string $column, ?string $from, ?string $through): Builder
    {
        if ($from !== null && $from !== '') {
            $query->where($column, '>=', self::viewerDay($from)->startUtc);
        }

        if ($through !== null && $through !== '') {
            $query->where($column, '<', self::viewerDay($through)->endUtcExclusive);
        }

        return $query;
    }

    /** As constrainViewerRange(), but anchored to the platform reporting timezone. */
    public static function constrainReportingRange(Builder $query, string $column, ?string $from, ?string $through): Builder
    {
        if ($from !== null && $from !== '') {
            $query->where($column, '>=', self::reportingDay($from)->startUtc);
        }

        if ($through !== null && $through !== '') {
            $query->where($column, '<', self::reportingDay($through)->endUtcExclusive);
        }

        return $query;
    }

    /** "Times shown in Europe/London" — so a filter never silently means a timezone the admin did not expect. */
    public static function viewerLabel(): string
    {
        return ViewerDateTime::timezoneFor();
    }

    public static function reportingLabel(): string
    {
        return ReportingTimezoneResolver::resolve();
    }
}
