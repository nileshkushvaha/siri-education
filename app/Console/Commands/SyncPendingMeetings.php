<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\MeetingStatus;
use App\Models\BookingMeeting;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Recovery sweep for meetings stuck in `pending` — Google's conference
 * creation is asynchronous, so an event insert can succeed while the
 * Meet link only materializes later; without this sweep such a meeting
 * would never resolve and participants would never receive a join URL.
 *
 * Re-invokes the normal, idempotent BookingMeetingService::createMeeting()
 * retry path pinned to the meeting's own provider (never the current
 * default, which an admin may have switched since): same-provider retry
 * updates the existing provider-side event, a resolved conference
 * transitions the row to Created and fires MeetingCreated (participant
 * notifications), and a conference that Google reports as failed lands
 * in the normal meeting_creation_failed audit/notification path.
 *
 * Bounds: only meetings pending for at least a minute (skip ones a
 * live request is still creating) and whose booking is still confirmed
 * with a start time not already past — a meeting resolved after the
 * lesson window is over helps no one.
 */
final class SyncPendingMeetings extends Command
{
    protected $signature = 'meetings:sync-pending';

    protected $description = 'Re-sync meetings whose provider-side conference creation is still pending.';

    public function handle(BookingMeetingServiceInterface $meetings): int
    {
        $attempted = 0;
        $resolved = 0;

        BookingMeeting::query()
            ->where('status', MeetingStatus::Pending)
            ->where('updated_at', '<=', Carbon::now()->subMinute())
            ->where('ends_at', '>', Carbon::now())
            ->whereHas('booking', fn ($query) => $query->where('status', BookingStatus::Confirmed))
            ->with('booking')
            ->orderBy('starts_at')
            ->cursor()
            ->each(function (BookingMeeting $meeting) use ($meetings, &$attempted, &$resolved): void {
                $attempted++;

                try {
                    $synced = $meetings->createMeeting($meeting->booking, $meeting->provider);
                } catch (Throwable $e) {
                    // createMeeting persists+audits its own failures; anything
                    // escaping it must not abort the rest of the sweep.
                    $this->warn(sprintf('Meeting %s sync failed: %s', $meeting->id, $e->getMessage()));

                    return;
                }

                if ($synced?->status === MeetingStatus::Created) {
                    $resolved++;
                }
            });

        $this->info("Synced {$attempted} pending meeting(s); {$resolved} resolved.");

        return self::SUCCESS;
    }
}
