<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\AcceptsExternalSources;
use App\Booking\Contracts\MeetingProviderInterface;
use App\Booking\DTOs\RecordingLocator;
use App\Booking\DTOs\RecordingProviderReconciliation;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Enums\RecordingFailureCode;
use App\Booking\Enums\RecordingStatus;
use App\Booking\Exceptions\RecordingStorageException;
use App\Booking\Jobs\AttachExternalRecordingJob;
use App\Booking\Jobs\CaptureLessonRecordingJob;
use App\Booking\Registry\MeetingProviderRegistry;
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
use InvalidArgumentException;
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
    /**
     * The withhold reason lives on the audit trail entry (override_reason),
     * not on the row — it is evidence about an administrative decision,
     * which is exactly what the activity log is for. Bounded so a form
     * or API cannot turn one audit row into a document.
     */
    public const int WITHHOLD_REASON_MAX_LENGTH = 500;

    public function __construct(
        private readonly RecordingEligibilityResolver $eligibility,
        private readonly RecordingIngestionService $ingestion,
        private readonly RecordingLifecycleNotifier $lifecycle,
        private readonly RecordingStorageResolver $storage,
        private readonly MeetingProviderRegistry $providers,
    ) {}

    /**
     * Called once, right after a meeting is created for a confirmed
     * booking (BookingMeetingService::createMeeting()). Idempotent via
     * the unique idempotency_key — a retried/duplicate call for the
     * same meeting can never create a second row.
     */
    public function registerIfEligible(Booking $booking, BookingMeeting $meeting, MeetingProviderInterface $provider, bool $logIneligible = true): ?Recording
    {
        $result = $this->eligibility->evaluate($booking, $provider);

        if (! $result->eligible) {
            // Operational breadcrumb, not an audit event: a lesson with no
            // recording row must be explainable ("provider cannot record",
            // "recording disabled") without an operator reverse-engineering
            // the eligibility gates. Info level — this fires for every lesson
            // the platform deliberately does not record. The sweep's
            // re-evaluation passes $logIneligible = false so a lesson that is
            // still ineligible is not re-logged every fifteen minutes.
            if ($logIneligible) {
                Log::info('Lesson recording not registered.', [
                    'booking_id' => $booking->id,
                    'booking_meeting_id' => $meeting->id,
                    'provider' => $provider->key(),
                    'reason' => $result->reason,
                ]);
            }

            return null;
        }

        $idempotencyKey = 'recording:'.$meeting->id;

        $existing = Recording::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            // The booking's single meeting row is reused when a cancelled
            // meeting is replaced on another provider. The recording is
            // keyed on that row, so it can still name the OLD provider —
            // and the capture job selects its adapter from the recording.
            // Re-point it when nothing was captured; keep it when a
            // transfer is in flight or stored (audited either way).
            return $existing->provider === $provider->key()
                ? $existing
                : $this->reconcileProvider($existing, audit: true)->recording;
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
     * Brings a recording row into agreement with the provider of the
     * meeting it belongs to, after that meeting was replaced.
     *
     * Re-points the row ONLY when nothing has been captured under the
     * old provider (status Pending or Failed, no storage locator), the
     * meeting itself is live (Created) on the new provider, AND the
     * lesson is eligible to be recorded on that provider right now —
     * the same RecordingEligibilityResolver gates registration applies
     * (booking status, participants active, consent, provider
     * capability), re-evaluated here because the queued job and the
     * operator command reach this without passing through registration.
     * The attempt budget restarts because the old attempts asked the
     * wrong provider; the consent evidence is re-snapshotted alongside
     * the original. A row that is Transferring, Stored, Available or
     * Expired — or that already holds a locator — is never relabelled,
     * never cleared, never deleted: an operator decides.
     *
     * Locks the MEETING row first, then the recording — the same order
     * the ingestion claim uses — so a meeting replacement committing at
     * the same instant is either fully seen or not at all.
     *
     * @param  User|null  $admin  the operator, when invoked on demand; must hold
     *                            RecordingPolicy::retry for this recording
     *
     * @throws AuthorizationException when $admin may not repair recordings
     */
    public function reconcileProvider(Recording $recording, bool $audit = true, ?User $admin = null): RecordingProviderReconciliation
    {
        if ($admin !== null) {
            Gate::forUser($admin)->authorize('retry', $recording);
        }

        $outcome = DB::transaction(function () use ($recording): RecordingProviderReconciliation {
            $meeting = BookingMeeting::query()->whereKey($recording->booking_meeting_id)->lockForUpdate()->first();

            /** @var Recording $fresh */
            $fresh = Recording::query()->whereKey($recording->getKey())->lockForUpdate()->firstOrFail();
            $meetingProvider = $meeting?->provider;

            if ($meeting === null) {
                return new RecordingProviderReconciliation(RecordingProviderReconciliation::PROTECTED, $fresh, null, reason: 'The recording has no meeting row.');
            }

            if ($meetingProvider === $fresh->provider) {
                return new RecordingProviderReconciliation(RecordingProviderReconciliation::ALIGNED, $fresh, $meetingProvider);
            }

            if ($meeting->status !== MeetingStatus::Created) {
                return new RecordingProviderReconciliation(RecordingProviderReconciliation::PROTECTED, $fresh, $meetingProvider, reason: sprintf('The meeting is %s, not created; nothing to re-point at.', $meeting->status->value));
            }

            $nothingCaptured = in_array($fresh->status, [RecordingStatus::Pending, RecordingStatus::Failed], true)
                && $fresh->storage_path === null;

            if (! $nothingCaptured) {
                return new RecordingProviderReconciliation(RecordingProviderReconciliation::PROTECTED, $fresh, $meetingProvider, reason: sprintf(
                    'The recording is %s%s under %s; an in-flight or stored transfer is never relabelled.',
                    $fresh->status->value,
                    $fresh->storage_path !== null ? ' with a storage locator' : '',
                    $fresh->provider,
                ));
            }

            // Eligibility on the DESTINATION provider, exactly as at
            // registration. A lesson that may not be recorded on Zoom is
            // not recovered onto Zoom just because it once qualified on
            // Google Meet.
            if (! $this->providers->has($meetingProvider)) {
                return new RecordingProviderReconciliation(RecordingProviderReconciliation::PROTECTED, $fresh, $meetingProvider, reason: sprintf('Provider %s is not registered; the recording cannot be re-pointed to it.', $meetingProvider));
            }

            $booking = $fresh->booking;

            if ($booking === null) {
                return new RecordingProviderReconciliation(RecordingProviderReconciliation::PROTECTED, $fresh, $meetingProvider, reason: 'The recording has no booking.');
            }

            $eligibility = $this->eligibility->evaluate($booking, $this->providers->get($meetingProvider));

            if (! $eligibility->eligible) {
                return new RecordingProviderReconciliation(RecordingProviderReconciliation::PROTECTED, $fresh, $meetingProvider, reason: sprintf('The lesson is not eligible for recording on %s (%s).', $meetingProvider, (string) $eligibility->reason));
            }

            $previous = (string) $fresh->provider;

            $fresh->fill([
                'provider' => $meetingProvider,
                // The old provider's identifiers mean nothing to the new one.
                'provider_reference' => null,
                'status' => RecordingStatus::Pending,
                'capture_attempts' => 0,
                'failure_code' => null,
                'failed_at' => null,
                'transfer_started_at' => null,
                // Evidence: the original snapshot stays; the re-evaluation
                // at re-point time is recorded beside it.
                'consent_snapshot' => [
                    ...($fresh->consent_snapshot ?? []),
                    'realigned' => [
                        'from_provider' => $previous,
                        'to_provider' => $meetingProvider,
                        'student_consented' => (bool) $booking->student?->profile?->consents_to_recording,
                        'instructor_consented' => (bool) $booking->instructor?->profile?->consents_to_recording,
                        'at' => now()->toIso8601String(),
                    ],
                ],
            ])->save();

            return new RecordingProviderReconciliation(RecordingProviderReconciliation::REALIGNED, $fresh, $meetingProvider, $previous);
        });

        if ($outcome->decision === RecordingProviderReconciliation::REALIGNED) {
            $this->lifecycle->recordingProviderRealigned($outcome->recording, (string) $outcome->previousProvider, $admin);
        } elseif ($audit && $outcome->decision === RecordingProviderReconciliation::PROTECTED) {
            $this->lifecycle->recordingProviderMismatchRetained($outcome->recording, $outcome->meetingProvider, (string) $outcome->reason);
        }

        return $outcome;
    }

    /**
     * Reconciliation for the ROW, not the bytes: registers a recording for
     * every created meeting that ended inside the retry window, belongs to
     * a confirmed or completed booking, has no recording row, and is
     * eligible NOW. Closes the gap where a lesson ran while a switch was
     * off (or a provider credential was missing) and the switches were
     * fixed afterwards — the artifact is still at the provider, and the
     * capture pipeline can still fetch it, but nothing would ever look.
     *
     * Bounded (retry window, batch size), idempotent (the unique
     * idempotency_key makes a second registration return the existing
     * row), and quiet for lessons that remain ineligible.
     *
     * @return int number of recordings newly registered
     */
    public function registerMissing(MeetingProviderRegistry $registry, int $retryMinutes, int $batchSize): int
    {
        $registered = 0;

        BookingMeeting::query()
            ->where('status', MeetingStatus::Created)
            ->whereDoesntHave('recording')
            ->whereBetween('ends_at', [now()->subMinutes(max(1, $retryMinutes)), now()])
            ->whereHas('booking', fn ($query) => $query->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Completed]))
            ->with('booking')
            ->orderBy('ends_at')
            ->limit(max(1, $batchSize))
            ->get()
            ->each(function (BookingMeeting $meeting) use ($registry, &$registered): void {
                if ($meeting->booking === null || ! $registry->has($meeting->provider)) {
                    return;
                }

                $recording = $this->registerIfEligible($meeting->booking, $meeting, $registry->get($meeting->provider), logIneligible: false);

                if ($recording?->wasRecentlyCreated) {
                    $registered++;
                    CaptureLessonRecordingJob::dispatch($recording->id);
                }
            });

        return $registered;
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
     * over an object that already exists: a Failed row that still holds
     * a locator (publication refused after a meeting replacement) is
     * refused here — see retryRefusalReason().
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

            // Enforced HERE, under the lock, not only by hiding a button:
            // a row that still points at a preserved object must never be
            // sent back through the pipeline, which would fetch again and
            // overwrite the locator — orphaning the object it preserved.
            if ($this->retryRefusalReason($fresh) !== null) {
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
     * Why an ordinary retry is refused for this Failed row, or null when
     * it may be retried. Two cases, both of which need operator recovery
     * rather than another pass through the pipeline:
     *
     *  - the meeting was replaced while this row was being captured
     *    (RecordingFailureCode::MeetingReplacedDuringCapture) — the object
     *    belongs to the OLD meeting and must not be re-fetched under the
     *    new one;
     *  - the row still holds a storage locator — retrying would fetch
     *    and store again, overwriting the pointer to a preserved object.
     *
     * Read-only; retryFailed() re-evaluates it under the row lock.
     */
    public function retryRefusalReason(Recording $recording): ?string
    {
        if ($recording->status !== RecordingStatus::Failed) {
            return null;
        }

        if ($recording->failure_code === RecordingFailureCode::MeetingReplacedDuringCapture) {
            return 'The meeting was replaced while this recording was being captured. The stored object belongs to the previous meeting; operator recovery is required.';
        }

        if ($recording->storage_path !== null) {
            return 'This recording still points at a stored object. Retrying would overwrite that pointer; operator recovery is required.';
        }

        return null;
    }

    // ── Manual recovery: attach an object by hand ─────────────────────

    /**
     * Whether the configured storage can take an operator-supplied
     * object at all. Decides whether the admin action exists; the
     * service refuses regardless when it cannot.
     */
    public function supportsManualAttach(): bool
    {
        try {
            return $this->storage->default() instanceof AcceptsExternalSources;
        } catch (RecordingStorageException) {
            return false;
        }
    }

    /**
     * Why an object may not be attached to this recording right now, or
     * null when it may. Read-only; attachExternal() re-evaluates it
     * under the row lock.
     */
    public function attachRefusalReason(Recording $recording): ?string
    {
        if (! in_array($recording->status, [RecordingStatus::Pending, RecordingStatus::Failed], true)) {
            return sprintf('This recording is %s; only a pending or failed recording can have an object attached.', $recording->status->label());
        }

        if ($recording->storage_path !== null) {
            return 'This recording already points at a stored object. Attaching would overwrite that pointer; operator recovery is required.';
        }

        if (! $this->supportsManualAttach()) {
            return 'The configured recording storage cannot take an attached file.';
        }

        return null;
    }

    /**
     * Manual recovery: an administrator attaches an object the pipeline
     * failed to deliver. The reference is resolved through the storage
     * backend FIRST (proving the platform can read it and that it is a
     * recording) so an unusable reference is refused at the click, with
     * the backend's own explanation, before anything is queued. The row
     * is then re-pointed at the operator's decision and the copy runs in
     * the background through the same claim/store/verify pipeline as any
     * other recording. Audited as an override with the mandatory reason.
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException when the reason is missing or the row refuses
     * @throws RecordingStorageException when the reference cannot be used
     */
    public function attachExternal(Recording $recording, User $admin, string $reference, string $reason, bool $registeredByOverride = false): void
    {
        Gate::forUser($admin)->authorize('attach', Recording::class);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::WITHHOLD_REASON_MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('A reason of 1–%d characters is required to attach a recording by hand.', self::WITHHOLD_REASON_MAX_LENGTH));
        }

        $storage = $this->storage->default();

        if (! $storage instanceof AcceptsExternalSources) {
            throw new InvalidArgumentException('The configured recording storage cannot take an attached file.');
        }

        // Proves access and type now; the job resolves again when it runs.
        $storage->resolveExternalSource($reference);

        DB::transaction(function () use ($recording, $admin, $reason, $registeredByOverride): void {
            /** @var Recording $fresh */
            $fresh = Recording::query()->whereKey($recording->getKey())->lockForUpdate()->firstOrFail();

            if (($refusal = $this->attachRefusalReason($fresh)) !== null) {
                throw new InvalidArgumentException($refusal);
            }

            $this->lifecycle->manuallyAttached($fresh, $admin, $reason, $registeredByOverride);

            $fresh->fill([
                'status' => RecordingStatus::Pending,
                'source' => Recording::SOURCE_MANUAL,
                'failure_code' => null,
                'failed_at' => null,
                'capture_attempts' => 0,
                'transfer_started_at' => null,
            ])->save();
        });

        AttachExternalRecordingJob::dispatch($recording->getKey(), trim($reference));
    }

    /**
     * Manual recovery for a lesson the pipeline never registered a
     * recording for (recording off, provider unable, participants
     * ineligible at the time): creates the row under an audited
     * override so an object can be attached to it. Idempotent per
     * meeting; refuses a lesson that never had a meeting or was never
     * confirmed.
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function registerManual(Booking $booking, User $admin): Recording
    {
        Gate::forUser($admin)->authorize('attach', Recording::class);

        $meeting = $booking->meeting;

        if ($meeting === null) {
            throw new InvalidArgumentException('This lesson has no meeting, so there is nothing to attach a recording to.');
        }

        if (! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::Completed], true)) {
            throw new InvalidArgumentException(sprintf('Only a confirmed or completed lesson can have a recording attached; this one is %s.', $booking->status->label()));
        }

        $existing = $booking->recording;

        if ($existing !== null) {
            return $existing;
        }

        $idempotencyKey = 'recording:manual:'.$meeting->id;

        try {
            $recording = Recording::query()->create([
                'booking_meeting_id' => $meeting->id,
                'booking_id' => $booking->id,
                'student_id' => $booking->student_id,
                'teacher_id' => $booking->instructor_id,
                'provider' => (string) $meeting->provider,
                'source' => Recording::SOURCE_MANUAL,
                'status' => RecordingStatus::Pending,
                'idempotency_key' => $idempotencyKey,
                'consent_snapshot' => [
                    'student_consented' => (bool) $booking->student?->profile?->consents_to_recording,
                    'instructor_consented' => (bool) $booking->instructor?->profile?->consents_to_recording,
                    'registered_by_override' => true,
                    'registered_by' => $admin->id,
                    'snapshotted_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (QueryException) {
            return Recording::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }

        $this->lifecycle->recordingRegistered($recording);

        return $recording;
    }

    /** Entry point for AttachExternalRecordingJob — the copy/verify/publish pass for an operator-attached object. */
    public function ingestExternal(Recording $recording, string $reference): void
    {
        $this->ingestion->ingestExternal($recording, $reference);
    }

    /**
     * Withholds one recording from its student (SRS §12.20 — access
     * rules are configurable; this is the per-recording exception to
     * the platform rule). Authorized, audited as an override with the
     * reason, idempotent: withholding an already-withheld recording
     * changes nothing and logs nothing. Never touches the object,
     * the lifecycle, retention, or administrative access.
     */
    public function withholdStudentAccess(Recording $recording, User $admin, string $reason): bool
    {
        Gate::forUser($admin)->authorize('withhold', $recording);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::WITHHOLD_REASON_MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'A reason of 1–%d characters is required to withhold a recording from its student.',
                self::WITHHOLD_REASON_MAX_LENGTH,
            ));
        }

        return (bool) DB::transaction(function () use ($recording, $admin, $reason): bool {
            /** @var Recording $fresh */
            $fresh = Recording::query()->whereKey($recording->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->isStudentAccessWithheld()) {
                return false;
            }

            $fresh->fill([
                'student_access_revoked_at' => now(),
                'student_access_revoked_by' => $admin->id,
            ])->save();

            $this->lifecycle->studentAccessWithheld($fresh, $admin, $reason);

            return true;
        });
    }

    /** The inverse of withholdStudentAccess(); same guarantees. */
    public function restoreStudentAccess(Recording $recording, User $admin): bool
    {
        Gate::forUser($admin)->authorize('withhold', $recording);

        return (bool) DB::transaction(function () use ($recording, $admin): bool {
            /** @var Recording $fresh */
            $fresh = Recording::query()->whereKey($recording->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->isStudentAccessWithheld()) {
                return false;
            }

            $fresh->fill([
                'student_access_revoked_at' => null,
                'student_access_revoked_by' => null,
            ])->save();

            $this->lifecycle->studentAccessRestored($fresh, $admin);

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
