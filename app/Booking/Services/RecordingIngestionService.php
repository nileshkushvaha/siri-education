<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\DiscoversRecordingArtifacts;
use App\Booking\Contracts\MeetingProviderInterface;
use App\Booking\Contracts\MeetingRecordingProviderInterface;
use App\Booking\Contracts\RecordingStorage;
use App\Booking\Contracts\SupportsNativeIngestion;
use App\Booking\DTOs\DiscoveredRecording;
use App\Booking\DTOs\NativeIngestionRequest;
use App\Booking\DTOs\NativeRecordingSource;
use App\Booking\DTOs\ProviderRecordingResult;
use App\Booking\DTOs\RecordingLocator;
use App\Booking\DTOs\RecordingStorageRequest;
use App\Booking\DTOs\StagedRecordingFile;
use App\Booking\Enums\RecordingFailureCode;
use App\Booking\Enums\RecordingStatus;
use App\Booking\Exceptions\RecordingIngestionException;
use App\Booking\Exceptions\RecordingStorageException;
use App\Booking\Storage\RecordingStorageResolver;
use App\Models\Recording;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Moves one recording from the meeting provider into SIRI-owned
 * storage. This is the only place that performs the transfer, and it
 * is deliberately the only long-running step in the whole feature —
 * it runs exclusively from a queued job or the reconciliation sweep,
 * never from an HTTP request.
 *
 * Three properties matter more than anything else here:
 *
 * NO LONG TRANSACTION. The claim, the store, and the publish are three
 * SHORT transactions with the download and upload happening BETWEEN
 * them. Holding a row lock (or an InnoDB transaction) open across a
 * multi-gigabyte upload would pin a connection for minutes and block
 * every other writer on the table.
 *
 * NO DOUBLE BINARY. A row is claimed with an atomic Pending →
 * Transferring transition under a row lock, so two workers racing on
 * the same recording produce exactly one uploader; the loser exits
 * immediately. The (storage_driver, storage_path) unique index is the
 * database-level backstop if they ever both reach storage anyway.
 *
 * NO PREMATURE "AVAILABLE". Upload success is recorded as Stored, not
 * Available. Only verification against the storage backend promotes a
 * recording to Available — the state students and instructors can
 * actually be served from. A crash in between resumes at verification
 * rather than re-uploading.
 *
 * Failure here never touches lesson completion, booking payment,
 * instructor earnings, or wallet settlement: recording persistence is
 * an independent post-lesson workflow, and the SRS establishes no
 * dependency in either direction.
 */
final class RecordingIngestionService
{
    public function __construct(
        private readonly RecordingStorageResolver $storage,
        private readonly RecordingFileNamer $namer,
        private readonly RecordingLifecycleNotifier $lifecycle,
        private readonly MeetingSettings $settings,
    ) {}

    /**
     * One ingestion attempt. Never throws for an ordinary recording
     * failure — every outcome is recorded on the row — so one bad
     * recording can never abort a sweep or poison a queue worker.
     */
    public function ingest(Recording $recording, MeetingProviderInterface $provider): void
    {
        $claim = $this->claim($recording, $provider);

        if ($claim === null) {
            return;
        }

        [$claimed, $resumeLocator] = $claim;
        $staged = null;

        try {
            // Resume path: a previous run uploaded the object but died
            // before verifying it. Re-verify what is already there
            // instead of transferring the same video a second time.
            if ($resumeLocator !== null) {
                $this->verifyAndPublish($claimed, $resumeLocator);

                return;
            }

            // Providers that can locate an artifact without moving it
            // get the two-step path, which is what makes a
            // backend-side transfer possible at all.
            if ($provider instanceof DiscoversRecordingArtifacts) {
                $discovered = $provider->discoverRecording($claimed->bookingMeeting);

                if ($discovered === null) {
                    $this->notReady($claimed);

                    return;
                }

                $this->reportExtraArtifacts($claimed, $discovered);

                $locator = $this->ingestDiscovered($claimed, $provider, $discovered, $staged);
                $this->verifyAndPublish($claimed->refresh(), $locator);

                return;
            }

            $result = $provider->fetchRecording($claimed->bookingMeeting);

            if ($result === null) {
                $this->notReady($claimed);

                return;
            }

            $staged = $result->file;

            $locator = $this->store($claimed, $result);
            $this->verifyAndPublish($claimed->refresh(), $locator);
        } catch (RecordingIngestionException $e) {
            $this->settle($claimed, $e->failureCode, $e);
        } catch (RecordingStorageException $e) {
            $this->settle($claimed, $e->failureCode, $e);
        } catch (Throwable $e) {
            // An unclassified provider-side failure. Treated as a
            // transient download failure so the retry window — not a
            // guess about the exception type — decides the outcome.
            $this->settle($claimed, RecordingFailureCode::SourceDownloadFailed, $e);
        } finally {
            // Always, on every path. A retry re-downloads from the
            // provider, so staged bytes are never the only copy and
            // deleting them can never lose a recording.
            $staged?->delete();
        }
    }

    /**
     * Chooses how the discovered artifact actually gets into storage.
     *
     * Backend-side copy first, when the artifact already lives in the
     * destination backend — the Google Meet case, where the recording
     * is an object in the very Drive we are writing to. Pulling several
     * gigabytes down only to push the identical bytes back up would
     * cost bandwidth, disk and time for no benefit whatsoever.
     *
     * If the backend cannot take it natively — S3 later, or a Drive
     * copy the account is not permitted to make — this falls through
     * to the ordinary staged/streamed pipeline within the SAME attempt,
     * so the recording is not delayed a whole retry cycle by an
     * optimization that did not apply.
     *
     * @param  StagedRecordingFile|null  $staged  set by reference so the caller's finally block cleans up whatever this staged
     */
    private function ingestDiscovered(
        Recording $recording,
        DiscoversRecordingArtifacts $provider,
        DiscoveredRecording $discovered,
        ?StagedRecordingFile &$staged,
    ): RecordingLocator {
        $storage = $this->storage->default();
        $source = $discovered->nativeSource;

        if ($source !== null && $storage instanceof SupportsNativeIngestion && $storage->canIngestNatively($source)) {
            try {
                return $this->storeNatively($recording, $storage, $discovered, $source);
            } catch (RecordingStorageException $e) {
                if ($e->failureCode !== RecordingFailureCode::StorageNativeCopyUnavailable) {
                    throw $e;
                }

                Log::info('Backend-side recording copy unavailable; falling back to streamed ingestion', [
                    'recording_id' => $recording->getKey(),
                    'storage_driver' => $storage->key(),
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        $staged = $provider->stageRecording($discovered);

        return $this->store($recording, new ProviderRecordingResult(
            providerReference: $discovered->providerReference,
            file: $staged,
            durationSeconds: $discovered->durationSeconds,
            recordedAt: $discovered->recordedAt,
        ));
    }

    /**
     * Records a backend-side copy as Stored. Size and checksum come
     * from what the DESTINATION reports about the copy it made — never
     * from what the source provider claimed — so verification compares
     * the backend against itself and a truncated copy still fails.
     */
    private function storeNatively(
        Recording $recording,
        SupportsNativeIngestion&RecordingStorage $storage,
        DiscoveredRecording $discovered,
        NativeRecordingSource $source,
    ): RecordingLocator {
        $recordedAt = $discovered->recordedAt;
        $extension = RecordingStagingArea::extensionFor($discovered->mimeType ?? 'video/mp4', 'recording.mp4');

        $stored = $storage->ingestNatively(new NativeIngestionRequest(
            source: $source,
            displayName: $this->namer->displayNameForExtension($this->withRecordedAt($recording, $recordedAt), $extension),
            partitionedAt: $recordedAt,
        ));

        try {
            DB::transaction(function () use ($recording, $discovered, $stored, $recordedAt): void {
                /** @var Recording $fresh */
                $fresh = Recording::query()->whereKey($recording->getKey())->lockForUpdate()->firstOrFail();

                $fresh->fill([
                    'status' => RecordingStatus::Stored,
                    'provider_reference' => $discovered->providerReference,
                    'storage_driver' => $stored->locator->driver,
                    'storage_path' => $stored->locator->path,
                    // No local file ever existed, so there is no sha256
                    // of our own to record. Verification uses the
                    // backend's size (and md5 where it supplies one).
                    'storage_checksum' => null,
                    'duration_seconds' => $discovered->durationSeconds,
                    'size_bytes' => $stored->remoteSizeBytes,
                    'mime_type' => $discovered->mimeType ?? 'video/mp4',
                    'recorded_at' => $recordedAt,
                    'stored_at' => now(),
                ])->save();
            });
        } catch (QueryException $e) {
            $this->discardOrphan($storage, $stored->locator);

            throw $e;
        }

        return $stored->locator;
    }

    /**
     * Google legitimately returns one recording per record/stop
     * session, so a lesson recorded in two halves has two artifacts.
     * SIRI's domain is one recording per lesson (SRS §12.18-21 defines
     * no multi-part model), and the deterministic rule is "the
     * earliest" — but the extra segments are never dropped SILENTLY.
     * An operator gets told, so the product decision can be made with
     * evidence rather than discovered by a student.
     */
    private function reportExtraArtifacts(Recording $recording, DiscoveredRecording $discovered): void
    {
        if ($discovered->artifactCount <= 1) {
            return;
        }

        $this->lifecycle->multipleArtifactsDiscovered($recording, $discovered->artifactCount);
    }

    // ── State transitions ──────────────────────────────────────────────

    /**
     * Atomically takes ownership of the row. Returns null when there
     * is nothing to do — already settled, already being transferred by
     * another worker, or the provider cannot supply recordings at all.
     *
     * @return array{0: Recording, 1: RecordingLocator|null}|null
     */
    private function claim(Recording $recording, MeetingProviderInterface $provider): ?array
    {
        return DB::transaction(function () use ($recording, $provider): ?array {
            /** @var Recording $fresh */
            $fresh = Recording::query()->whereKey($recording->getKey())->lockForUpdate()->firstOrFail();

            // Anything other than Pending/Stored is either finished or
            // owned by another worker right now.
            if (! in_array($fresh->status, [RecordingStatus::Pending, RecordingStatus::Stored], true)) {
                return null;
            }

            if (! $provider instanceof MeetingRecordingProviderInterface || ! $provider->supportsRecording()) {
                $this->fail($fresh, RecordingFailureCode::ProviderCapabilityMissing);

                return null;
            }

            $resumeLocator = $fresh->status === RecordingStatus::Stored
                ? RecordingLocator::fromRecording($fresh)
                : null;

            $fresh->fill([
                'status' => RecordingStatus::Transferring,
                'transfer_started_at' => now(),
                'capture_attempts' => $fresh->capture_attempts + 1,
            ])->save();

            return [$fresh, $resumeLocator];
        });
    }

    /** Back to Pending without penalty — the next sweep retries. */
    private function release(Recording $recording, bool $refundAttempt = false): void
    {
        DB::transaction(function () use ($recording, $refundAttempt): void {
            /** @var Recording $fresh */
            $fresh = Recording::query()->whereKey($recording->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status !== RecordingStatus::Transferring) {
                return;
            }

            $fresh->fill([
                'status' => RecordingStatus::Pending,
                'transfer_started_at' => null,
                'capture_attempts' => $refundAttempt ? max(0, $fresh->capture_attempts - 1) : $fresh->capture_attempts,
            ])->save();
        });
    }

    /**
     * The provider has no artifact for this meeting YET. Google generates
     * a Meet recording minutes to hours after the conference, and a
     * lesson nobody recorded never produces one at all — so "not ready"
     * is neither a failure nor a wasted attempt:
     *
     *  - inside the retry window the row goes back to Pending and the
     *    attempt is REFUNDED. The attempt budget exists for real
     *    failures; a slow Google must not consume it, or five quiet
     *    sweeps (75 minutes) would strand a legitimate recording in
     *    Pending with no attempts left and no failure recorded;
     *  - once the window (recording_capture_retry_minutes after the
     *    lesson ended) has closed, the recording fails permanently as
     *    SourceNotFound. That is the terminal state a student sees as
     *    "unavailable" rather than "processing" forever, and the
     *    administrator sees as an alert with a stable label.
     */
    private function notReady(Recording $recording): void
    {
        $endsAt = $recording->bookingMeeting?->ends_at;
        $windowClosed = $endsAt !== null && now()->greaterThan(
            $endsAt->addMinutes(max(0, $this->settings->recording_capture_retry_minutes)),
        );

        if ($windowClosed) {
            $this->failLocked($recording, RecordingFailureCode::SourceNotFound);

            return;
        }

        $this->release($recording, refundAttempt: true);
    }

    /**
     * Streams the staged file into the configured backend and records
     * the resulting locator as Stored — the point from which a stored
     * object is known to (possibly) exist, so no retry ever uploads a
     * second copy blindly.
     */
    private function store(Recording $recording, ProviderRecordingResult $result): RecordingLocator
    {
        $storage = $this->storage->default();

        $recordedAt = $result->recordedAt;

        $stored = $storage->put(new RecordingStorageRequest(
            file: $result->file,
            displayName: $this->namer->displayName($this->withRecordedAt($recording, $recordedAt), $result->file),
            partitionedAt: $recordedAt,
        ));

        try {
            DB::transaction(function () use ($recording, $result, $stored, $recordedAt): void {
                /** @var Recording $fresh */
                $fresh = Recording::query()->whereKey($recording->getKey())->lockForUpdate()->firstOrFail();

                $fresh->fill([
                    'status' => RecordingStatus::Stored,
                    'provider_reference' => $result->providerReference,
                    'storage_driver' => $stored->locator->driver,
                    'storage_path' => $stored->locator->path,
                    'storage_checksum' => $result->file->checksum,
                    'duration_seconds' => $result->durationSeconds,
                    'size_bytes' => $result->file->sizeBytes,
                    'mime_type' => $result->file->mimeType,
                    'recorded_at' => $recordedAt,
                    'stored_at' => now(),
                ])->save();
            });
        } catch (QueryException $e) {
            // The (storage_driver, storage_path) unique index rejected
            // this locator: another row already owns that object. The
            // copy we just uploaded is the duplicate, so remove it
            // rather than leaving an orphan behind in storage.
            $this->discardOrphan($storage, $stored->locator);

            throw $e;
        }

        return $stored->locator;
    }

    /**
     * The only transition into Available. Verification asks the
     * backend what it actually holds; a mismatch is a failure, not a
     * warning, because a truncated recording that students can open
     * is worse than one they can see is missing.
     */
    private function verifyAndPublish(Recording $recording, RecordingLocator $locator): void
    {
        $storage = $this->storage->forRecording($recording);

        $storage->verify($locator, (int) $recording->size_bytes, $recording->storage_checksum);

        $published = DB::transaction(function () use ($recording): ?Recording {
            /** @var Recording $fresh */
            $fresh = Recording::query()->whereKey($recording->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status === RecordingStatus::Available) {
                return null; // another worker already published it
            }

            $fresh->fill([
                'status' => RecordingStatus::Available,
                'failure_code' => null,
                'failed_at' => null,
                'transfer_started_at' => null,
                'available_at' => now(),
                'expires_at' => now()->addDays(max(1, $this->settings->recording_retention_days)),
            ])->save();

            return $fresh;
        });

        if ($published !== null) {
            $this->lifecycle->recordingBecameAvailable($published);
        }
    }

    /**
     * Decides between "retry later" and "give up", from the failure
     * code plus the bounded attempt/time budget — never from the
     * exception class or its message.
     */
    private function settle(Recording $recording, RecordingFailureCode $code, Throwable $e): void
    {
        // Structured, safe context only. No signed URLs, no tokens, no
        // Authorization headers — adapters sanitize before throwing,
        // and the exception message is the sanitized diagnostic.
        Log::warning('Recording ingestion attempt failed', [
            'recording_id' => $recording->getKey(),
            'provider' => $recording->provider,
            'storage_driver' => $recording->storage_driver ?? config('recordings.storage_driver'),
            'failure_code' => $code->value,
            'attempts' => $recording->capture_attempts,
            'reason' => $e->getMessage(),
        ]);

        if ($code->isPermanent()) {
            $this->failLocked($recording, $code);

            return;
        }

        $endsAt = $recording->bookingMeeting?->ends_at;
        $withinWindow = $endsAt === null || now()->lessThanOrEqualTo(
            $endsAt->addMinutes(max(0, $this->settings->recording_capture_retry_minutes)),
        );
        $attemptsLeft = $recording->capture_attempts < max(1, $this->settings->recording_capture_max_attempts);

        if ($withinWindow && $attemptsLeft) {
            $this->release($recording);

            return;
        }

        $this->failLocked($recording, RecordingFailureCode::RetriesExhausted);
    }

    private function failLocked(Recording $recording, RecordingFailureCode $code): void
    {
        DB::transaction(function () use ($recording, $code): void {
            /** @var Recording $fresh */
            $fresh = Recording::query()->whereKey($recording->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status->isTerminal()) {
                return;
            }

            $this->fail($fresh, $code);
        });
    }

    /** Caller must already hold the row lock. */
    private function fail(Recording $recording, RecordingFailureCode $code): void
    {
        $recording->fill([
            'status' => RecordingStatus::Failed,
            'failure_code' => $code,
            'failed_at' => now(),
            'transfer_started_at' => null,
        ])->save();

        $this->lifecycle->recordingFailed($recording, $code);
    }

    /**
     * Best-effort cleanup of a duplicate object we uploaded but cannot
     * own. Never allowed to mask the original error.
     */
    private function discardOrphan(RecordingStorage $storage, RecordingLocator $locator): void
    {
        try {
            $storage->delete($locator);
        } catch (Throwable $e) {
            Log::warning('Orphaned recording object could not be removed from storage', [
                'storage_driver' => $locator->driver,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The file name uses the recording's recorded_at, which is only
     * known once the provider answers — so hand the namer a copy
     * carrying it, without touching the persisted row yet.
     */
    private function withRecordedAt(Recording $recording, CarbonImmutable $recordedAt): Recording
    {
        $copy = clone $recording;
        $copy->recorded_at = $recordedAt;

        return $copy;
    }
}
