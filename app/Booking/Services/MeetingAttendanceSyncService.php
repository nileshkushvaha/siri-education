<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\BookingMeetingRepositoryInterface;
use App\Booking\Contracts\MeetingAttendanceIngestionServiceInterface;
use App\Booking\Contracts\MeetingAttendanceProviderInterface;
use App\Booking\Contracts\MeetingAttendanceSyncServiceInterface;
use App\Booking\Enums\MeetingAttendanceProcessingStatus;
use App\Booking\Registry\MeetingProviderRegistry;
use App\Models\BookingMeeting;
use App\Models\MeetingAttendanceProviderEvent;
use App\Settings\MeetingSettings;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pull-based attendance reconciliation. Complements webhooks: a sync
 * pull that overlaps webhook evidence never double-counts, because the
 * attendance aggregates merge intervals as a union. Providers without
 * attendance-sync support are marked and skipped;
 * transient provider failures retry within the configured window and
 * attempt budget, then settle as permanent. One meeting's failure
 * never stops the batch. Logs carry ids and reasons only.
 */
final class MeetingAttendanceSyncService implements MeetingAttendanceSyncServiceInterface
{
    public function __construct(
        private readonly BookingMeetingRepositoryInterface $meetings,
        private readonly MeetingProviderRegistry $registry,
        private readonly MeetingAttendanceIngestionServiceInterface $ingestion,
        private readonly MeetingSettings $settings,
    ) {}

    public function sync(bool $force = false): int
    {
        if (! $this->settings->attendance_sync_enabled) {
            return 0;
        }

        $endedBefore = now()->subMinutes(max(0, $this->settings->attendance_sync_delay_minutes));
        $endedAfter = now()->subHours(max(1, $this->settings->attendance_sync_max_age_hours));
        $settled = 0;

        $due = $this->meetings->dueForAttendanceSync(
            $endedAfter,
            $endedBefore,
            $this->settings->attendance_sync_max_attempts,
            $this->settings->attendance_sync_batch_size,
            $force,
        );

        foreach ($due as $meeting) {
            try {
                $settled += $this->syncMeeting($meeting) ? 1 : 0;
            } catch (Throwable $e) {
                $this->recordFailure($meeting, $e);
            }
        }

        return $settled;
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function syncMeeting(BookingMeeting $meeting): bool
    {
        $adapter = $this->registry->has($meeting->provider) ? $this->registry->get($meeting->provider) : null;

        if (! $adapter instanceof MeetingAttendanceProviderInterface || ! $adapter->supportsAttendanceSync()) {
            $meeting->fill(['attendance_sync_status' => 'unsupported'])->save();

            return false;
        }

        $meeting->fill(['attendance_sync_attempts' => $meeting->attendance_sync_attempts + 1])->save();

        // May throw AttendanceSyncUnavailableException — handled as transient.
        $events = $adapter->fetchAttendance($meeting);

        $row = MeetingAttendanceProviderEvent::query()->create([
            'provider' => $meeting->provider,
            'provider_event_id' => sprintf('sync:%s:%d', $meeting->id, $meeting->attendance_sync_attempts),
            'meeting_reference' => $meeting->provider_meeting_id,
            'booking_meeting_id' => $meeting->id,
            'source' => 'sync',
            'signature_valid' => true,
            'processing_status' => 'received',
            'normalized_events' => array_map(fn ($e) => $e->toArray(), $events),
            'received_at' => now()->toImmutable()->utc(),
        ]);

        $row = $this->ingestion->process($row);

        $meeting->fill([
            'attendance_synced_at' => now()->toImmutable()->utc(),
            'attendance_sync_status' => match ($row->processing_status) {
                MeetingAttendanceProcessingStatus::Processed => 'synced',
                MeetingAttendanceProcessingStatus::Review => 'review',
                MeetingAttendanceProcessingStatus::Ignored => 'ignored',
                default => $row->processing_status->value,
            },
        ])->save();

        return true;
    }

    private function recordFailure(BookingMeeting $meeting, Throwable $e): void
    {
        $withinRetryWindow = now()->lessThanOrEqualTo(
            $meeting->ends_at->addMinutes(max(0, $this->settings->attendance_sync_retry_minutes)),
        );
        $attemptsLeft = $meeting->attendance_sync_attempts < max(1, $this->settings->attendance_sync_max_attempts);

        $meeting->fill([
            'attendance_sync_status' => $withinRetryWindow && $attemptsLeft ? 'failed_transient' : 'failed_permanent',
        ])->save();

        Log::warning('Meeting attendance sync failed', [
            'booking_meeting_id' => $meeting->id,
            'provider' => $meeting->provider,
            'attempts' => $meeting->attendance_sync_attempts,
            'status' => $meeting->attendance_sync_status,
            'error' => $e->getMessage(),
        ]);
    }
}
