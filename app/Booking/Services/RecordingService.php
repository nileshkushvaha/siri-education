<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\MeetingProviderInterface;
use App\Booking\DTOs\RecordingLocator;
use App\Booking\Enums\RecordingStatus;
use App\Booking\Storage\RecordingStorageResolver;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Models\Recording;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SRS §12.18-21/31 — the single authoritative boundary for the
 * recording DOMAIN: eligibility, registration, retention/expiry,
 * access authorization, and controlled retry. Neither
 * BookingMeetingService, a meeting provider, nor a Filament resource
 * ever writes to the `recordings` table directly.
 *
 * What this class deliberately does NOT do is move bytes. The
 * provider→storage transfer lives in RecordingIngestionService, and
 * where the bytes physically go is decided by the RecordingStorage
 * abstraction. Nothing here knows whether that is Google Drive, a
 * local disk, or S3 — which is the property that makes the future S3
 * migration a configuration change rather than a rewrite.
 */
final class RecordingService
{
    public function __construct(
        private readonly RecordingEligibilityResolver $eligibility,
        private readonly RecordingIngestionService $ingestion,
        private readonly RecordingLifecycleNotifier $lifecycle,
        private readonly RecordingStorageResolver $storage,
    ) {}

    /**
     * Called once, right after a meeting is created for a confirmed
     * booking (BookingMeetingService::createMeeting()). Idempotent via
     * the unique idempotency_key — a retried/duplicate call for the
     * same meeting can never create a second row.
     */
    public function registerIfEligible(Booking $booking, BookingMeeting $meeting, MeetingProviderInterface $provider): ?Recording
    {
        $result = $this->eligibility->evaluate($booking, $provider);

        if (! $result->eligible) {
            return null;
        }

        $idempotencyKey = 'recording:'.$meeting->id;

        $existing = Recording::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $recording = Recording::query()->create([
                'booking_meeting_id' => $meeting->id,
                'booking_id' => $booking->id,
                'student_id' => $booking->student_id,
                'teacher_id' => $booking->instructor_id,
                'provider' => $provider->key(),
                'status' => RecordingStatus::Pending,
                'idempotency_key' => $idempotencyKey,
                'consent_snapshot' => [
                    'student_consented' => (bool) $booking->student?->profile?->consents_to_recording,
                    'instructor_consented' => (bool) $booking->instructor?->profile?->consents_to_recording,
                    'snapshotted_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (QueryException $e) {
            // A concurrent registration (e.g. an update() retry racing
            // the original create()) already won the unique index —
            // the idempotent outcome, not a failure.
            if (str_contains($e->getMessage(), 'recordings_idempotency_key_unique') || str_contains($e->getMessage(), 'idempotency_key')) {
                return Recording::query()->where('idempotency_key', $idempotencyKey)->first();
            }

            throw $e;
        }

        $this->lifecycle->recordingRegistered($recording);

        return $recording;
    }

    /**
     * One ingestion attempt for a single recording — the shared entry
     * point for the queued CaptureLessonRecordingJob and the
     * recordings:capture reconciliation sweep. Both paths run the
     * identical claim/store/verify pipeline, which is why a webhook
     * replay, a redelivered job, a concurrent worker and a sweep all
     * converge on one canonical recording and one stored object.
     */
    public function capture(Recording $recording, MeetingProviderInterface $provider): void
    {
        $this->ingestion->ingest($recording, $provider);
    }

    /**
     * Returns rows abandoned mid-transfer (worker crash, OOM kill,
     * lost queue worker) to Pending so the pipeline can retry them.
     * Bounded by the caller's batch size. Safe because Transferring
     * carries no storage locator yet — anything that got as far as
     * uploading is Stored, and resumes at verification instead.
     *
     * @return int number of recordings reclaimed
     */
    public function reclaimStalledTransfers(int $staleMinutes, int $batchSize): int
    {
        $reclaimed = 0;

        Recording::query()
            ->stalledInTransfer($staleMinutes)
            ->limit(max(1, $batchSize))
            ->get()
            ->each(function (Recording $recording) use (&$reclaimed): void {
                DB::transaction(function () use ($recording, &$reclaimed): void {
                    /** @var Recording $fresh */
                    $fresh = Recording::query()->whereKey($recording->getKey())->lockForUpdate()->firstOrFail();

                    if ($fresh->status !== RecordingStatus::Transferring) {
                        return;
                    }

                    $fresh->fill(['status' => RecordingStatus::Pending, 'transfer_started_at' => null])->save();
                    $reclaimed++;
                });
            });

        return $reclaimed;
    }

    /**
     * Retention (SRS §12.21). Deletes the stored OBJECT and keeps the
     * metadata row as historical/audit evidence — "recording metadata
     * may remain even after file deletion".
     *
     * Order matters: the storage object is deleted FIRST and the row
     * is only marked Expired once that succeeded. Flipping the row
     * first would leave an undeletable orphan in Drive or S3 with
     * nothing left pointing at it.
     *
     * @return int number of recordings transitioned to Expired
     */
    public function expireDueRecordings(int $batchSize): int
    {
        $expired = 0;

        Recording::query()->dueForExpiry()->limit(max(1, $batchSize))->get()->each(function (Recording $recording) use (&$expired): void {
            $locator = RecordingLocator::fromRecording($recording);

            if ($locator !== null) {
                try {
                    $this->storage->forRecording($recording)->delete($locator);
                } catch (Throwable $e) {
                    // Leave the row Available and try again on the next
                    // sweep — never claim a deletion that did not happen.
                    Log::warning('Recording retention deletion failed', [
                        'recording_id' => $recording->getKey(),
                        'storage_driver' => $recording->storage_driver,
                        'reason' => $e->getMessage(),
                    ]);

                    return;
                }
            }

            DB::transaction(function () use ($recording, &$expired): void {
                /** @var Recording $fresh */
                $fresh = Recording::query()->whereKey($recording->getKey())->lockForUpdate()->firstOrFail();

                if ($fresh->status !== RecordingStatus::Available) {
                    return;
                }

                // Metadata (duration/size/mime/timestamps) stays on the
                // row; only the locator is cleared, since the object it
                // pointed to no longer exists. storage_driver is kept as
                // evidence of where the recording used to live.
                $fresh->fill([
                    'status' => RecordingStatus::Expired,
                    'storage_path' => null,
                ])->save();

                $this->lifecycle->recordingExpired($fresh);
                $expired++;
            });
        });

        return $expired;
    }

    /**
     * Controlled operator recovery for a permanently failed recording:
     * returns it to Pending with a fresh attempt budget so the normal
     * pipeline picks it up again.
     *
     * Idempotent and concurrency-safe — only a Failed row transitions,
     * so a double-clicked admin action, or a retry racing an in-flight
     * transfer, cannot start two ingestions. Retrying never re-uploads
     * an object that already exists: a row holding a locator is Stored
     * or Available, neither of which this touches.
     */
    public function retryFailed(Recording $recording, User $admin): bool
    {
        Gate::forUser($admin)->authorize('retry', $recording);

        return (bool) DB::transaction(function () use ($recording, $admin): bool {
            /** @var Recording $fresh */
            $fresh = Recording::query()->whereKey($recording->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status !== RecordingStatus::Failed) {
                return false;
            }

            $this->lifecycle->retryRequested($fresh, $admin);

            $fresh->fill([
                'status' => RecordingStatus::Pending,
                'failure_code' => null,
                'failed_at' => null,
                'capture_attempts' => 0,
                'transfer_started_at' => null,
            ])->save();

            return true;
        });
    }

    /**
     * The RecordingPolicy IS the authorization source of truth (project
     * convention — "Policies handle authorization"); this method is the
     * "access authorization" entry point the download controller and
     * any other caller use, so nobody calls Gate directly and drifts
     * from the policy.
     *
     * @throws AuthorizationException
     */
    public function assertCanAccess(User $viewer, Recording $recording): void
    {
        Gate::forUser($viewer)->authorize('view', $recording);
    }
}
