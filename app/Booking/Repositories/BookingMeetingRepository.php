<?php

declare(strict_types=1);

namespace App\Booking\Repositories;

use App\Booking\Contracts\BookingMeetingRepositoryInterface;
use App\Booking\Enums\MeetingStatus;
use App\Models\BookingMeeting;
use Carbon\CarbonInterface;
use Illuminate\Support\LazyCollection;

final class BookingMeetingRepository implements BookingMeetingRepositoryInterface
{
    public function findByProviderReference(string $provider, string $reference): ?BookingMeeting
    {
        if ($reference === '') {
            return null;
        }

        return BookingMeeting::query()
            ->where('provider', $provider)
            ->where('provider_meeting_id', $reference)
            ->first();
    }

    public function dueForAttendanceSync(
        CarbonInterface $endedAfter,
        CarbonInterface $endedBefore,
        int $maxAttempts,
        int $chunkSize,
        bool $force = false,
    ): LazyCollection {
        return BookingMeeting::query()
            ->where('status', MeetingStatus::Created)
            ->whereNotNull('provider_meeting_id')
            ->whereBetween('ends_at', [$endedAfter, $endedBefore])
            ->unless($force, fn ($query) => $query
                ->whereNull('attendance_synced_at')
                ->where('attendance_sync_attempts', '<', max(1, $maxAttempts))
                ->where(fn ($q) => $q
                    ->whereNull('attendance_sync_status')
                    ->orWhereNotIn('attendance_sync_status', ['failed_permanent', 'unsupported'])))
            ->lazyById(max(1, $chunkSize));
    }
}
