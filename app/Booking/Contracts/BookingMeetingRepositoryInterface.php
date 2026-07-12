<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Models\BookingMeeting;
use Carbon\CarbonInterface;
use Illuminate\Support\LazyCollection;

/** Meeting queries for the attendance ingestion/sync layer. */
interface BookingMeetingRepositoryInterface
{
    public function findByProviderReference(string $provider, string $reference): ?BookingMeeting;

    /**
     * Created meetings whose own end time falls inside
     * [endedAfter, endedBefore], ordered deterministically (lazyById in
     * chunkSize chunks — never the full set in memory). Settled meetings
     * (attendance_synced_at set), permanent failures, and meetings past
     * maxAttempts are excluded unless $force.
     *
     * @return LazyCollection<int, BookingMeeting>
     */
    public function dueForAttendanceSync(
        CarbonInterface $endedAfter,
        CarbonInterface $endedBefore,
        int $maxAttempts,
        int $chunkSize,
        bool $force = false,
    ): LazyCollection;
}
