<?php

declare(strict_types=1);

namespace App\Booking\Repositories;

use App\Booking\Contracts\AvailabilityRepositoryInterface;
use App\Models\Holiday;
use App\Models\TeacherAvailability;
use App\Models\TeacherUnavailability;
use App\Models\User;
use App\Support\Timezone\IanaTimezone;
use App\Support\Timezone\LocalDay;
use App\Support\UserTimezoneResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class AvailabilityRepository implements AvailabilityRepositoryInterface
{
    public function windowsFor(int $teacherId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $rows = TeacherAvailability::query()
            ->active()
            ->forTeacher($teacherId)
            ->with('teacher.profile')
            ->get();

        $windows = new Collection;

        foreach ($rows as $row) {
            $timezone = $row->timezone ?: self::teacherTimezone($row);
            $localFrom = $from->setTimezone($timezone)->startOfDay();
            $localTo = $to->setTimezone($timezone)->endOfDay();

            for ($date = $localFrom; $date->lessThanOrEqualTo($localTo); $date = $date->addDay()) {
                if ($row->day_of_week->value !== $date->dayOfWeek) {
                    continue;
                }

                if ($row->effective_from !== null && $date->lessThan($row->effective_from)) {
                    continue;
                }

                if ($row->effective_until !== null && $date->greaterThan($row->effective_until)) {
                    continue;
                }

                $startsAt = $date->setTimeFromTimeString($row->start_time)->utc();
                $endsAt = $date->setTimeFromTimeString($row->end_time)->utc();

                if ($endsAt->greaterThan($from) && $startsAt->lessThan($to)) {
                    $windows->push(['starts_at' => $startsAt, 'ends_at' => $endsAt]);
                }
            }
        }

        return $windows->sortBy('starts_at')->values();
    }

    public function windowCovers(int $teacherId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        $rows = TeacherAvailability::query()
            ->active()
            ->forTeacher($teacherId)
            ->with('teacher.profile')
            ->get();

        return $this->rowsCover($rows, $startsAt, $endsAt);
    }

    /**
     * The coverage calculation itself, extracted so a HYPOTHETICAL
     * window set (an availability mutation's proposed
     * after-state, built from unsaved model clones) can be evaluated
     * with the exact same timezone/day-of-week/effective-range/midnight
     * semantics as the live windowCovers() check — never a second
     * implementation. $fallbackTimezone covers rows whose teacher
     * relation isn't loaded (clones).
     *
     * @param  iterable<TeacherAvailability>  $rows
     */
    public function rowsCover(iterable $rows, CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?string $fallbackTimezone = null): bool
    {
        if (! $startsAt->isSameDay($endsAt) && ! $endsAt->equalTo($startsAt->addDay()->startOfDay())) {
            return false;
        }

        foreach ($rows as $row) {
            if (! $row->is_active) {
                continue;
            }

            $timezone = $row->timezone
                ?: ($row->relationLoaded('teacher') ? self::teacherTimezone($row) : null)
                ?: $fallbackTimezone
                ?: UserTimezoneResolver::platformDefault();
            $localStart = $startsAt->setTimezone($timezone);
            $localEnd = $endsAt->setTimezone($timezone);

            if ($row->day_of_week->value !== $localStart->dayOfWeek) {
                continue;
            }

            if (! $localStart->isSameDay($localEnd) && ! $localEnd->equalTo($localStart->addDay()->startOfDay())) {
                continue;
            }

            if ($row->effective_from !== null && $localStart->lessThan($row->effective_from)) {
                continue;
            }

            if ($row->effective_until !== null && $localStart->greaterThan($row->effective_until)) {
                continue;
            }

            $endTime = $localStart->isSameDay($localEnd) ? $localEnd->format('H:i:s') : '24:00:00';

            if ($row->start_time <= $localStart->format('H:i:s') && $row->end_time >= $endTime) {
                return true;
            }
        }

        return false;
    }

    /**
     * TZ-1: the defensive fallback for a window row whose own
     * `timezone` column is somehow blank. Every row written through
     * InstructorAvailabilityService carries one, and the 2026_07_14
     * migration backfilled the rest, so this path should be
     * unreachable — but when it is reached it now resolves the
     * teacher's real timezone through the canonical chain
     * (profile -> Country -> platform -> UTC) instead of silently
     * treating the window as if it had been authored in UTC.
     *
     * Takes an already-loaded teacher only. Callers guard with
     * relationLoaded() where the row may be an unsaved clone, so this
     * never turns a bulk coverage check into an N+1.
     */
    private static function teacherTimezone(TeacherAvailability $row): string
    {
        $teacher = $row->teacher;

        return $teacher !== null
            ? UserTimezoneResolver::resolve($teacher)
            : UserTimezoneResolver::platformDefault();
    }

    public function hasBlackout(int $teacherId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        return TeacherUnavailability::query()
            ->forTeacher($teacherId)
            ->overlapping($startsAt, $endsAt)
            ->exists();
    }

    public function blackoutsFor(int $teacherId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return TeacherUnavailability::query()
            ->forTeacher($teacherId)
            ->overlapping($from, $to)
            ->get();
    }

    /**
     * TZ-2A — see AvailabilityRepositoryInterface for the contract.
     *
     * Reads the timezone off the instructor's own active availability
     * rules rather than jumping straight to their profile, because that
     * column is what already governs when their windows actually occur;
     * deriving the calendar day from a different source than the
     * windows would let a slot exist on one day and be capped on
     * another.
     *
     * Distinct timezones across a teacher's rows are not a supported
     * configuration (InstructorAvailabilityService writes one timezone
     * per mutation), so the earliest-created rule's timezone wins and
     * the outcome stays deterministic rather than depending on row
     * order. With no rules at all — a brand-new instructor — the
     * canonical user timezone answers it.
     */
    public function calendarTimezoneFor(int $teacherId): string
    {
        $ruleTimezone = TeacherAvailability::query()
            ->active()
            ->forTeacher($teacherId)
            ->whereNotNull('timezone')
            ->orderBy('created_at')
            ->orderBy('id')
            ->value('timezone');

        if (IanaTimezone::isValid($ruleTimezone)) {
            return $ruleTimezone;
        }

        $teacher = User::query()->find($teacherId);

        return $teacher !== null
            ? UserTimezoneResolver::resolve($teacher)
            : UserTimezoneResolver::platformDefault();
    }

    public function isHoliday(CarbonImmutable $date, string $timezone): bool
    {
        // The holiday is a calendar date, so the comparison must happen
        // in the calendar that owns it. `$date` arrives as a UTC
        // instant; its UTC date is an artifact of storage and is a
        // different day from the instructor's for a large part of every
        // day in most of the world.
        return Holiday::query()
            ->onDate(LocalDay::containing($date, $timezone)->date)
            ->exists();
    }

    public function holidayDatesBetween(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Holiday::query()
            // Widened by a day either side: a UTC range's edges can sit
            // on a local date outside it (up to ~14h of offset spread),
            // and a holiday missing from this set would silently fail to
            // exclude its slots. Over-fetching is free — the caller
            // matches on exact local dates.
            ->between($from->subDay(), $to->addDay())
            ->pluck('date')
            ->map(fn ($date): string => $date->toDateString());
    }
}
