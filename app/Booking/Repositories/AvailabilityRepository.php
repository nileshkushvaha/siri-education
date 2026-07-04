<?php

declare(strict_types=1);

namespace App\Booking\Repositories;

use App\Booking\Contracts\AvailabilityRepositoryInterface;
use App\Booking\Enums\Weekday;
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
            ->get();

        $windows = new Collection;

        for ($date = $from->startOfDay(); $date->lessThan($to); $date = $date->addDay()) {
            foreach ($rows as $row) {
                if ($row->day_of_week->value !== $date->dayOfWeek) {
                    continue;
                }

                if ($row->effective_from !== null && $date->lessThan($row->effective_from)) {
                    continue;
                }

                if ($row->effective_until !== null && $date->greaterThan($row->effective_until)) {
                    continue;
                }

                $startsAt = $date->setTimeFromTimeString($row->start_time);
                $endsAt = $date->setTimeFromTimeString($row->end_time);

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

        return TeacherAvailability::query()
            ->active()
            ->forTeacher($teacherId)
            ->forDay(Weekday::from($startsAt->dayOfWeek))
            ->effectiveOn($startsAt)
            ->where('start_time', '<=', $startsAt->format('H:i:s'))
            ->where('end_time', '>=', $startsAt->isSameDay($endsAt) ? $endsAt->format('H:i:s') : '24:00:00')
            ->exists();
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
