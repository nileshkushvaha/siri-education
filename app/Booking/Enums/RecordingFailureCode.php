<?php

declare(strict_types=1);

namespace App\Booking\Enums;

/**
 * Why a recording did not reach Available. Persisted as
 * recordings.failure_code and surfaced to admins as a stable label —
 * a raw exception message is NEVER stored here or shown to a user.
 *
 * isPermanent() is the retry decision: a permanent code stops the
 * pipeline immediately (retrying cannot change the outcome), a
 * transient one leaves the row Pending for the bounded retry window.
 */
enum RecordingFailureCode: string
{
    /**
     * The booking's meeting was replaced (another provider, or the same
     * provider with a new remote meeting id) while this recording was
     * being captured. The uploaded object is kept under its locator and
     * never published as the new meeting's recording; an operator
     * decides. Permanent: retrying would publish the wrong lesson.
     */
    case MeetingReplacedDuringCapture = 'meeting_replaced_during_capture';

    /** The active meeting provider does not (or no longer) offer recording retrieval. */
    case ProviderCapabilityMissing = 'provider_capability_missing';

    /** The provider's download link/asset is gone — refetching cannot recover it. */
    case SourceExpired = 'source_expired';

    /** Transient failure fetching the recording from the provider. */
    case SourceDownloadFailed = 'source_download_failed';

    /**
     * The provider refused access to the artifact. Almost always an
     * OAuth scope missing from the domain-wide delegation grant, which
     * an operator fixes without any code change — hence transient.
     */
    case SourceAccessDenied = 'source_access_denied';

    /** The provider API is rate limiting or out of quota. Always transient. */
    case SourceRateLimited = 'source_rate_limited';

    /** Source violates a hard safety limit (size ceiling, disallowed content type). */
    case SourceRejected = 'source_rejected';

    /** Storage backend is missing/invalid configuration or credentials. */
    case StorageNotConfigured = 'storage_not_configured';

    /** Storage credentials were rejected (revoked key, missing delegation scope). */
    case StorageAuthFailed = 'storage_auth_failed';

    /** Storage backend is out of space/quota. */
    case StorageQuotaExceeded = 'storage_quota_exceeded';

    /** Transient failure writing the object to storage. */
    case StorageUploadFailed = 'storage_upload_failed';

    /** The object was written but does not match what we sent (size/checksum/missing). */
    case StorageVerificationFailed = 'storage_verification_failed';

    /** A stored object could not be read back for delivery or deletion. */
    case StorageReadFailed = 'storage_read_failed';

    /**
     * The backend declined a server-side copy of a source that already
     * lives inside it. Never persisted as a recording failure — the
     * pipeline falls back to streaming within the same attempt.
     */
    case StorageNativeCopyUnavailable = 'storage_native_copy_unavailable';

    /** The bounded retry window or attempt budget ran out. */
    case RetriesExhausted = 'capture_retries_exhausted';

    /**
     * An operator attached an object the platform account cannot read
     * (not shared with it, deleted, or a mistyped reference). Permanent
     * for this attempt: the operator fixes the sharing and attaches again.
     */
    case ExternalSourceInaccessible = 'external_source_inaccessible';

    /** An operator attached something that is not a recording (wrong type, in the trash, or over the size ceiling). */
    case ExternalSourceUnsupported = 'external_source_unsupported';

    /**
     * The provider never produced a recording inside the retry window —
     * nobody pressed Record, the conference produced no artifact, or
     * Google never finished generating one. Permanent: the window IS
     * the business bound (recording_capture_retry_minutes), and a row
     * left Pending forever would show a student "processing" forever.
     */
    case SourceNotFound = 'source_not_found';

    /**
     * Storage-auth and quota are deliberately NOT permanent: both are
     * routinely fixed by an operator (re-grant delegation, free space)
     * while the retry window is still open, and a stalled recording is
     * worse than a few wasted attempts.
     */
    public function isPermanent(): bool
    {
        return match ($this) {
            self::MeetingReplacedDuringCapture,
            self::ProviderCapabilityMissing,
            self::SourceExpired,
            self::SourceRejected,
            self::StorageNotConfigured,
            self::RetriesExhausted,
            self::SourceNotFound,
            self::ExternalSourceInaccessible,
            self::ExternalSourceUnsupported => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MeetingReplacedDuringCapture => 'Meeting was replaced while its recording was being captured',
            self::ProviderCapabilityMissing => 'Provider cannot supply recordings',
            self::SourceExpired => 'Provider recording no longer available',
            self::SourceNotFound => 'No recording was produced for this lesson',
            self::SourceDownloadFailed => 'Download from provider failed',
            self::SourceAccessDenied => 'Provider denied access to the recording',
            self::SourceRateLimited => 'Provider rate limit reached',
            self::SourceRejected => 'Recording rejected by safety limits',
            self::StorageNotConfigured => 'Recording storage not configured',
            self::StorageAuthFailed => 'Recording storage authentication failed',
            self::StorageQuotaExceeded => 'Recording storage quota exceeded',
            self::StorageUploadFailed => 'Upload to recording storage failed',
            self::StorageVerificationFailed => 'Stored recording failed verification',
            self::StorageReadFailed => 'Stored recording could not be read',
            self::StorageNativeCopyUnavailable => 'Backend-side copy unavailable; streamed instead',
            self::RetriesExhausted => 'Retries exhausted',
            self::ExternalSourceInaccessible => 'Attached file is not visible to the platform account',
            self::ExternalSourceUnsupported => 'Attached file is not a usable recording',
        };
    }

    /** A few words for a table cell; label() is the full sentence. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::MeetingReplacedDuringCapture => 'Meeting replaced mid-capture',
            self::ProviderCapabilityMissing => 'Provider cannot record',
            self::SourceExpired => 'Provider recording gone',
            self::SourceNotFound => 'No recording produced',
            self::SourceDownloadFailed => 'Provider download failed',
            self::SourceAccessDenied => 'Provider access denied',
            self::SourceRateLimited => 'Provider rate limited',
            self::SourceRejected => 'Rejected by safety limits',
            self::StorageNotConfigured => 'Storage not configured',
            self::StorageAuthFailed => 'Storage auth failed',
            self::StorageQuotaExceeded => 'Storage quota exceeded',
            self::StorageUploadFailed => 'Storage upload failed',
            self::StorageVerificationFailed => 'Verification failed',
            self::StorageReadFailed => 'Stored object unreadable',
            self::StorageNativeCopyUnavailable => 'Streamed copy used',
            self::RetriesExhausted => 'Retries exhausted',
            self::ExternalSourceInaccessible => 'Attached file not visible',
            self::ExternalSourceUnsupported => 'Attached file unusable',
        };
    }

    /**
     * What an operator does about it — safe to show on an admin screen
     * (no locators, URLs or exception text). Retry eligibility itself is
     * decided by RecordingService::retryRefusalReason(), never here.
     */
    public function operatorGuidance(): string
    {
        return match ($this) {
            self::MeetingReplacedDuringCapture => 'The stored object belongs to the meeting that was replaced. An operator decides whether to keep it under the old meeting or discard it; an ordinary retry is refused so the object is never overwritten.',
            self::ProviderCapabilityMissing => 'The meeting provider in use cannot supply recordings. Nothing to retry; enable recording on a capable provider for future lessons.',
            self::SourceExpired => 'The provider no longer holds the recording. Unrecoverable; retrying cannot bring it back.',
            self::SourceNotFound => 'The provider reports no recording for this meeting — recording was never started, or the meeting ran under a different identity. Check with the instructor before retrying; a retry only helps if the provider now shows a recording.',
            self::SourceDownloadFailed => 'The download from the provider was interrupted. Transient: the sweep retries inside the capture window, or use Retry ingestion.',
            self::SourceAccessDenied => 'The provider refused access to the recording (credentials or scopes). Fix the provider authorization in Meetings, then retry.',
            self::SourceRateLimited => 'The provider throttled the request. Transient; wait for the sweep or retry later.',
            self::SourceRejected => 'The recording exceeded the configured safety limits (size or duration). Nothing to retry unless the limits are raised.',
            self::StorageNotConfigured => 'Recording storage is not configured (folder or credentials missing). Complete Meetings → Recording Storage, then retry.',
            self::StorageAuthFailed => 'The storage backend rejected the platform credentials. Re-grant the storage authorization, then retry.',
            self::StorageQuotaExceeded => 'The storage backend is out of space. Free space or raise the quota, then retry.',
            self::StorageUploadFailed => 'The upload to storage failed. Transient: the sweep retries, or use Retry ingestion.',
            self::StorageVerificationFailed => 'The stored object did not match what was uploaded (truncated or removed). Retry ingestion uploads afresh.',
            self::StorageReadFailed => 'The stored object could not be read back. Check the storage backend; retry re-verifies.',
            self::StorageNativeCopyUnavailable => 'Informational: the backend-side copy was unavailable and the file was streamed instead.',
            self::RetriesExhausted => 'The capture window closed without success. Find the earlier failure in the logs, fix its cause, then Retry ingestion once.',
            self::ExternalSourceInaccessible => 'The platform account cannot read the attached file. Share it with the platform meeting account (view access is enough), check the link, then attach it again.',
            self::ExternalSourceUnsupported => 'The attached file is not an accepted recording: it is in the trash, not a video/audio type, or above the size ceiling. Fix the file and attach it again.',
        };
    }
}
