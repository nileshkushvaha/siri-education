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

    public function recordingFailed(Recording $recording, RecordingFailureCode $code): void
    {
        $this->audit->logSystem(
            'recordings',
            'recording_failed',
            'Lesson recording could not be stored.',
            $recording,
            ['provider' => $recording->provider, 'failure_code' => $code->value],
        );

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
}
