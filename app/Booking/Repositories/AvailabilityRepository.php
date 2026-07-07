<?php

declare(strict_types=1);

namespace App\Booking\Repositories;

use App\Booking\Contracts\AvailabilityRepositoryInterface;
use App\Models\Holiday;
use App\Models\TeacherAvailability;
use App\Models\TeacherUnavailability;
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
            $timezone = $row->timezone ?: $row->teacher?->profile?->timezone ?: config('app.timezone', 'UTC');
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
        if (! $startsAt->isSameDay($endsAt) && ! $endsAt->equalTo($startsAt->addDay()->startOfDay())) {
            return false;
        }

        $rows = TeacherAvailability::query()
            ->active()
            ->forTeacher($teacherId)
            ->with('teacher.profile')
            ->get();

        foreach ($rows as $row) {
            $timezone = $row->timezone ?: $row->teacher?->profile?->timezone ?: config('app.timezone', 'UTC');
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

    public function isHoliday(CarbonImmutable $date): bool
    {
        return Holiday::query()->onDate($date)->exists();
    }

    public function holidayDatesBetween(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Holiday::query()
            ->between($from, $to)
            ->pluck('date')
            ->map(fn ($date): string => $date->toDateString());
    }
}
