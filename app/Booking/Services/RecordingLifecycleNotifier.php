<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Alerts\DTOs\OperationalAlertSignal;
use App\Alerts\Enums\OperationalAlertCategory;
use App\Alerts\Enums\OperationalAlertSeverity;
use App\Alerts\Enums\OperationalAlertType;
use App\Alerts\Services\OperationalAlertService;
use App\Booking\Enums\RecordingFailureCode;
use App\Models\Recording;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything a recording lifecycle transition emits outward: the audit
 * entry, the operational alert, and the participant notification.
 *
 * Split out of the ingestion pipeline so the transfer logic reads as
 * pure state machine, and so notifications keep exactly one home —
 * they fire on a canonical state transition, never from an upload
 * client, an HTTP handler, or a storage adapter. Idempotency is the
 * existing NotificationIdempotencyGuard's, keyed per recording and
 * participant, so a replayed job or a resumed verification cannot
 * re-notify anyone.
 *
 * NOBODY IS NOTIFIED WHEN A RECORDING BECOMES AVAILABLE. Recordings
 * are an administrative quality/evidence asset: students and
 * instructors have no access to them (RecordingPolicy), so telling
 * them one exists would advertise something they cannot open. The
 * availability transition is audited and visible in the admin
 * Recordings resource, which is where the people who may actually use
 * it already look — a per-recording email to admins would be pure
 * noise at lesson volume.
 *
 * This is distinct from recording NOTICE/CONSENT, which is about the
 * live session and is unaffected: participants still consent to being
 * recorded (RecordingEligibilityResolver, consent_snapshot) and still
 * see the provider's own in-meeting recording indicator.
 *
 * Failure, by contrast, IS an administrator signal (SRS §12.36
 * "Recording failed") and raises an operational alert.
 */
final class RecordingLifecycleNotifier
{
    public function __construct(
        private readonly AuditTrailService $audit,
        private readonly OperationalAlertService $alerts,
    ) {}

    public function recordingRegistered(Recording $recording): void
    {
        $this->audit->logSystem(
            'recordings',
            'recording_registered',
            'Lesson recording registered as eligible.',
            $recording,
            ['booking_meeting_id' => $recording->booking_meeting_id],
        );
    }

    public function recordingBecameAvailable(Recording $recording): void
    {
        $this->audit->logSystem(
            'recordings',
            'recording_available',
            'Lesson recording stored and verified.',
            $recording,
            [
                'provider' => $recording->provider,
                // The BACKEND, never the locator: a storage path in the
                // audit log is an out-of-band pointer to private video.
                'storage_driver' => $recording->storage_driver,
                'size_bytes' => $recording->size_bytes,
            ],
        );

    }

    /**
     * The meeting was replaced on another provider before anything had
     * been captured, and the row now names the meeting's provider.
     */
    public function recordingProviderRealigned(Recording $recording, string $previousProvider, ?User $admin = null): void
    {
        $properties = [
            'previous_provider' => $previousProvider,
            'provider' => $recording->provider,
            'booking_meeting_id' => $recording->booking_meeting_id,
        ];
        $description = sprintf('Lesson recording re-pointed from %s to %s after the meeting was replaced.', $previousProvider, $recording->provider);

        if ($admin !== null) {
            $this->audit->logUser($admin, 'recordings', 'recording_provider_realigned', $description, $recording, $properties);

            return;
        }

        $this->audit->logSystem('recordings', 'recording_provider_realigned', $description, $recording, $properties);
    }

    /**
     * The meeting moved to another provider but the row already holds
     * an in-flight or stored transfer, so it was deliberately left alone.
     * An operator decides; nothing is relabelled or deleted.
     */
    public function recordingProviderMismatchRetained(Recording $recording, ?string $meetingProvider, string $reason): void
    {
        $this->audit->logSystem(
            'recordings',
            'recording_provider_mismatch_retained',
            'Lesson recording kept on its original provider after the meeting was replaced.',
            $recording,
            [
                'provider' => $recording->provider,
                'meeting_provider' => $meetingProvider,
                'status' => $recording->status->value,
                'reason' => $reason,
            ],
        );
    }

    /** The provider's copy was moved to its recoverable trash after SIRI's copy was verified. */
    public function sourceRecordingDisposed(Recording $recording): void
    {
        $this->audit->logSystem(
            'recordings',
            'recording_source_disposed',
            'Provider copy of the lesson recording moved to the provider trash after verified persistence.',
            $recording,
            ['provider' => $recording->provider, 'storage_driver' => $recording->storage_driver],
        );
    }

    /** Disposal was attempted and refused; the SIRI copy is unaffected. */
    public function sourceRecordingDisposalFailed(Recording $recording, string $reason): void
    {
        $this->audit->logSystem(
            'recordings',
            'recording_source_disposal_failed',
            'Provider copy of the lesson recording could not be moved to the provider trash.',
            $recording,
            ['provider' => $recording->provider, 'reason' => Str::limit($reason, 300)],
        );
    }

    public function recordingFailed(Recording $recording, RecordingFailureCode $code): void
    {
        // The audit entry is the record of truth and is written inside
        // the caller's row transaction — if it cannot be written, the
        // failure is not settled. The operational alert is a courtesy
        // to administrators: it must never be what rolls that back.
        $this->audit->logSystem(
            'recordings',
            'recording_failed',
            'Lesson recording could not be stored.',
            $recording,
            ['provider' => $recording->provider, 'failure_code' => $code->value],
        );

        try {
            $this->alerts->createOrMerge(new OperationalAlertSignal(
                type: OperationalAlertType::RecordingCaptureFailed,
                category: OperationalAlertCategory::BookingMeeting,
                severity: OperationalAlertSeverity::Warning,
                title: 'Lesson recording capture failed',
                // The stable failure LABEL, never a raw exception message.
                summary: sprintf(
                    'Recording capture failed for booking %s after %d attempt(s): %s',
                    $recording->booking_id,
                    $recording->capture_attempts,
                    $code->label(),
                ),
                subjectType: Recording::class,
                subjectId: $recording->getKey(),
                metadata: ['failure_code' => $code->value, 'provider' => $recording->provider],
            ));
        } catch (Throwable $e) {
            try {
                Log::warning('Recording failure alert could not be raised', ['recording_id' => $recording->getKey(), 'reason' => $e->getMessage()]);
            } catch (Throwable) {
                // The audit row already carries the failure.
            }
        }
    }

    /**
     * Google can legitimately return several recordings for one
     * conference (each Record start/stop is its own artifact). SIRI
     * stores one recording per lesson, so the extras are not ingested
     * — but they are never dropped in silence: an admin is told so the
     * product decision about multi-part lessons can be made on
     * evidence. Deliberately a Warning, not an error: the lesson does
     * have a recording, it is just not the whole story.
     */
    public function multipleArtifactsDiscovered(Recording $recording, int $artifactCount): void
    {
        $this->audit->logSystem(
            'recordings',
            'recording_multiple_artifacts',
            'Provider returned multiple recording artifacts for one lesson; the earliest was ingested.',
            $recording,
            ['provider' => $recording->provider, 'artifact_count' => $artifactCount],
        );

        $this->alerts->createOrMerge(new OperationalAlertSignal(
            type: OperationalAlertType::RecordingMultipleArtifacts,
            category: OperationalAlertCategory::BookingMeeting,
            severity: OperationalAlertSeverity::Warning,
            title: 'Lesson recorded in multiple segments',
            summary: sprintf(
                'Booking %s produced %d recording artifacts. SIRI stores one recording per lesson and ingested the earliest; the remaining segments stay in the provider account only.',
                $recording->booking_id,
                $artifactCount,
            ),
            subjectType: Recording::class,
            subjectId: $recording->getKey(),
            metadata: ['artifact_count' => $artifactCount, 'provider' => $recording->provider],
        ));
    }

    public function recordingExpired(Recording $recording): void
    {
        $this->audit->logSystem(
            'recordings',
            'recording_expired',
            'Lesson recording expired; stored object deleted, metadata retained.',
            $recording,
            ['storage_driver' => $recording->storage_driver],
        );
    }

    public function retryRequested(Recording $recording, User $admin): void
    {
        $this->audit->logUser(
            $admin,
            'recordings',
            'recording_retry_requested',
            'Lesson recording ingestion retry requested by an administrator.',
            $recording,
            ['previous_failure_code' => $recording->failure_code?->value],
        );
    }

    /**
     * An administrator withholding one recording from its student is
     * an override of the platform playback policy, so it is recorded
     * as such — with the mandatory reason — rather than as a plain
     * action (AuditTrailService::logOverride).
     */
    public function studentAccessWithheld(Recording $recording, User $admin, string $reason): void
    {
        $this->audit->logOverride(
            $admin,
            'recordings',
            'recording_student_access_withheld',
            'Student access to a lesson recording withheld by an administrator.',
            $reason,
            $recording,
        );
    }

    /**
     * An administrator attaching an object outside the verified pipeline
     * is an override of "recordings come from the provider", so it is
     * recorded as one — with the mandatory reason — never as a plain
     * action. The reference itself is never written anywhere.
     */
    public function manuallyAttached(Recording $recording, User $admin, string $reason, bool $registeredByOverride): void
    {
        $this->audit->logOverride(
            $admin,
            'recordings',
            'recording_manually_attached',
            $registeredByOverride
                ? 'Lesson recording registered and attached by an administrator (the pipeline never registered one).'
                : 'Lesson recording attached by an administrator after the pipeline failed to deliver it.',
            $reason,
            $recording,
            ['previous_status' => $recording->status->value, 'previous_failure_code' => $recording->failure_code?->value, 'registered_by_override' => $registeredByOverride],
        );
    }

    public function studentAccessRestored(Recording $recording, User $admin): void
    {
        $this->audit->logUser(
            $admin,
            'recordings',
            'recording_student_access_restored',
            'Student access to a lesson recording restored by an administrator.',
            $recording,
        );
    }
}
