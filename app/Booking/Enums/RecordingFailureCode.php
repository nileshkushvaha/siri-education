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
     * Storage-auth and quota are deliberately NOT permanent: both are
     * routinely fixed by an operator (re-grant delegation, free space)
     * while the retry window is still open, and a stalled recording is
     * worse than a few wasted attempts.
     */
    public function isPermanent(): bool
    {
        return match ($this) {
            self::ProviderCapabilityMissing,
            self::SourceExpired,
            self::SourceRejected,
            self::StorageNotConfigured,
            self::RetriesExhausted => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ProviderCapabilityMissing => 'Provider cannot supply recordings',
            self::SourceExpired => 'Provider recording no longer available',
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
        };
    }
}
