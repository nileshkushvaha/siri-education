<?php

declare(strict_types=1);

namespace App\Booking\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Pure slot arithmetic — no persistence, no clock, fully unit-testable.
 * Intervals are half-open [starts_at, ends_at): touching intervals do
 * not conflict.
 */
final class SlotGenerator
{
    /**
     * Slice availability windows into candidate slots. Consecutive
     * slots are spaced by duration + buffer, so the buffer is honoured
     * between back-to-back bookings.
     *
     * @param  Collection<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>  $windows
     * @return Collection<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    public function candidates(Collection $windows, int $durationMinutes, int $bufferMinutes = 0): Collection
    {
        $step = $durationMinutes + max(0, $bufferMinutes);
        $candidates = new Collection;

        foreach ($windows as $window) {
            for (
                $start = $window['starts_at'];
                $start->addMinutes($durationMinutes)->lessThanOrEqualTo($window['ends_at']);
                $start = $start->addMinutes($step)
            ) {
                $candidates->push([
                    'starts_at' => $start,
                    'ends_at' => $start->addMinutes($durationMinutes),
                ]);
            }
        }

        return $candidates;
    }

    /**
     * Does [$startsAt, $endsAt), padded by the buffer on both sides,
     * intersect any busy interval?
     *
     * @param  Collection<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>  $busy
     */
    public function conflicts(
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        Collection $busy,
        int $bufferMinutes = 0,
    ): bool {
        $paddedStart = $startsAt->subMinutes(max(0, $bufferMinutes));
        $paddedEnd = $endsAt->addMinutes(max(0, $bufferMinutes));

        return $busy->contains(
            fn (array $interval): bool => $interval['starts_at']->lessThan($paddedEnd)
                && $interval['ends_at']->greaterThan($paddedStart),
        );
    }
}
