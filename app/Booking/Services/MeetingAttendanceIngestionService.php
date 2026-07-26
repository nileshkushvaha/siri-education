<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\BookingMeetingRepositoryInterface;
use App\Booking\Contracts\MeetingAttendanceIngestionServiceInterface;
use App\Booking\DTOs\ProviderAttendanceEvent;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\MeetingAttendanceProcessingStatus;
use App\Lessons\Contracts\LessonAttendanceServiceInterface;
use App\Lessons\DTOs\AttendanceEvidenceData;
use App\Lessons\Enums\AttendanceSource;
use App\Lessons\Enums\LessonParticipant;
use App\Lessons\Exceptions\LessonAttendanceException;
use App\Models\Lesson;
use App\Models\MeetingAttendanceProviderEvent;
use App\Settings\MeetingSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The bridge from provider events to lesson attendance evidence.
 * Everything accepted flows through LessonAttendanceService →
 * RecordAttendanceAction, so fingerprint idempotency, out-of-order
 * merging, interval-union overlap handling, late-evidence flagging,
 * sealed records, and cancelled-booking rejection all apply
 * consistently. Unknown meetings and unresolved/ambiguous participants
 * settle as operational review rows and never create attendance. Logs
 * carry ids and reasons only — no payloads, no participant
 * identifiers.
 */
final class MeetingAttendanceIngestionService implements MeetingAttendanceIngestionServiceInterface
{
    public function __construct(
        private readonly BookingMeetingRepositoryInterface $meetings,
        private readonly MeetingAttendanceParticipantResolver $resolver,
        private readonly LessonAttendanceServiceInterface $attendance,
        private readonly MeetingSettings $settings,
    ) {}

    public function process(MeetingAttendanceProviderEvent $event): MeetingAttendanceProviderEvent
    {
        $claimed = $this->claim($event);

        if ($claimed === null) {
            return $event->refresh();
        }

        try {
            return $this->ingest($claimed);
        } catch (Throwable $e) {
            $status = $claimed->attempts >= max(1, $this->settings->attendance_sync_max_attempts)
                ? MeetingAttendanceProcessingStatus::FailedPermanent
                : MeetingAttendanceProcessingStatus::FailedTransient;

            $claimed->fill([
                'processing_status' => $status,
                'status_reason' => mb_substr($e->getMessage(), 0, 255),
            ])->save();

            Log::warning('Meeting attendance ingestion failed', [
                'provider_event_id' => $claimed->id,
                'provider' => $claimed->provider,
                'attempts' => $claimed->attempts,
                'status' => $status->value,
                'error' => $e->getMessage(),
            ]);

            if ($status === MeetingAttendanceProcessingStatus::FailedTransient) {
                throw $e; // queued callers back off and retry; the row stays retryable
            }

            return $claimed;
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * Claim the row under a lock so two workers can never process the
     * same provider event concurrently; settled rows are never re-run.
     */
    private function claim(MeetingAttendanceProviderEvent $event): ?MeetingAttendanceProviderEvent
    {
        return DB::transaction(function () use ($event): ?MeetingAttendanceProviderEvent {
            $row = MeetingAttendanceProviderEvent::query()
                ->whereKey($event->getKey())
                ->lockForUpdate()
                ->first();

            if ($row === null || ! $row->processing_status->isProcessable()) {
                return null;
            }

            $row->fill(['attempts' => $row->attempts + 1])->save();

            return $row;
        });
    }

    private function ingest(MeetingAttendanceProviderEvent $event): MeetingAttendanceProviderEvent
    {
        $meeting = $this->meetings->findByProviderReference($event->provider, (string) $event->meeting_reference);

        if ($meeting === null) {
            return $this->settle($event, MeetingAttendanceProcessingStatus::Review, 'unknown_meeting');
        }

        $event->booking_meeting_id = $meeting->id;
        $booking = $meeting->booking;
        $lesson = $booking?->lesson;

        if ($booking === null || $lesson === null) {
            return $this->settle($event, MeetingAttendanceProcessingStatus::Review, 'missing_lesson');
        }

        $event->lesson_id = $lesson->id;

        // Evidence can never reactivate a cancelled booking — drop safely.
        if ($booking->status === BookingStatus::Cancelled) {
            return $this->settle($event, MeetingAttendanceProcessingStatus::Ignored, 'booking_cancelled');
        }

        $results = [];
        $needsReview = false;

        foreach ($event->normalized_events ?? [] as $data) {
            $item = ProviderAttendanceEvent::fromArray($data);
            $participant = $this->resolver->resolve($meeting, $booking, $item);

            if ($participant === null) {
                $results[] = ['event' => $item->providerEventId, 'outcome' => 'review', 'reason' => 'unknown_participant'];
                $needsReview = true;

                continue;
            }

            // A leave notification without join context carries no usable
            // interval — never invent a duration from it.
            if ($item->joinedAt === null && $item->attendedSeconds === null) {
                $results[] = ['event' => $item->providerEventId, 'outcome' => 'skipped', 'reason' => 'no_join_or_duration'];

                continue;
            }

            $results[] = $this->recordEvidence($lesson, $event, $item, $participant->value);
        }

        return $this->settle(
            $event,
            $needsReview ? MeetingAttendanceProcessingStatus::Review : MeetingAttendanceProcessingStatus::Processed,
            $needsReview ? 'unknown_participant' : null,
            $results,
        );
    }

    /** @return array<string, mixed> */
    private function recordEvidence(Lesson $lesson, MeetingAttendanceProviderEvent $event, ProviderAttendanceEvent $item, string $participant): array
    {
        $evidence = new AttendanceEvidenceData(
            participant: LessonParticipant::from($participant),
            source: $event->source === 'sync' ? AttendanceSource::ProviderSync : AttendanceSource::ProviderWebhook,
            joinedAt: $item->joinedAt,
            leftAt: $item->leftAt,
            attendedSeconds: $item->attendedSeconds,
            providerReference: $item->meetingReference,
            // Deterministic per participant-session: replays map to the
            // same fingerprint and dedup inside RecordAttendanceAction.
            providerEventId: implode('|', [$item->providerEventId, $item->participantKey, $item->eventType->value]),
            metadata: [...$item->metadata, 'provider' => $item->provider, 'event_type' => $item->eventType->value],
        );

        try {
            $result = $this->attendance->record($lesson, $evidence);
        } catch (LessonAttendanceException $e) {
            return ['event' => $item->providerEventId, 'outcome' => 'rejected', 'reason' => mb_substr($e->getMessage(), 0, 200)];
        }

        return [
            'event' => $item->providerEventId,
            'outcome' => match (true) {
                $result->late => 'late',
                $result->applied => 'applied',
                default => 'duplicate',
            },
        ];
    }

    /** @param list<array<string, mixed>> $results */
    private function settle(
        MeetingAttendanceProviderEvent $event,
        MeetingAttendanceProcessingStatus $status,
        ?string $reason = null,
        array $results = [],
    ): MeetingAttendanceProviderEvent {
        $event->fill([
            'processing_status' => $status,
            'status_reason' => $reason,
            'results' => $results ?: null,
            'processed_at' => now()->toImmutable()->utc(),
        ])->save();

        if ($status !== MeetingAttendanceProcessingStatus::Processed) {
            Log::info('Meeting attendance event settled without full processing', [
                'provider_event_id' => $event->id,
                'provider' => $event->provider,
                'status' => $status->value,
                'reason' => $reason,
            ]);
        }

        return $event;
    }
}
