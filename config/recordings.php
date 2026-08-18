<?php

use App\Booking\Storage\FilesystemRecordingStorage;
use App\Booking\Storage\GoogleDriveRecordingStorage;

/**
 * Recording BINARY STORAGE configuration.
 *
 * Deliberately separate from the meeting/recording BUSINESS settings
 * (MeetingSettings — eligibility, consent, retention, capture windows),
 * which stay admin-editable in the database. What lives here is purely
 * an operations/deployment concern: which storage backend holds the
 * bytes, and how the transfer is staged. Changing the backend is a
 * deploy-time decision, never a runtime admin toggle, because an
 * already-stored recording is always read back through the driver it
 * was written with (recordings.storage_driver on the row).
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Storage Driver
    |--------------------------------------------------------------------------
    |
    | Which RecordingStorage implementation NEW recordings are written
    | to. Existing recordings are always read/deleted through the driver
    | recorded on their own row, so flipping this never orphans anything.
    |
    | Supported: "filesystem" (any Laravel disk — local now, "s3" later),
    |            "google_drive"
    |
    */

    'storage_driver' => env('RECORDING_STORAGE_DRIVER', 'filesystem'),

    /*
    |--------------------------------------------------------------------------
    | Available Storage Drivers
    |--------------------------------------------------------------------------
    |
    | The complete map of driver key => RecordingStorage implementation
    | that RecordingStorageResolver can build. A key here is what gets
    | written to recordings.storage_driver, so it must stay stable once
    | any recording has been stored with it — existing rows are read
    | back through their own driver, not through the active default.
    |
    | Adding a backend is: implement RecordingStorage, add a line here.
    |
    */

    'drivers' => [
        FilesystemRecordingStorage::KEY => FilesystemRecordingStorage::class,
        GoogleDriveRecordingStorage::KEY => GoogleDriveRecordingStorage::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Filesystem Driver
    |--------------------------------------------------------------------------
    |
    | THIS IS THE S3 SEAM. Amazon S3 is reached by pointing this at the
    | existing "s3" disk — no new domain code, no new adapter:
    |
    |     RECORDING_STORAGE_DRIVER=filesystem
    |     RECORDING_STORAGE_DISK=s3
    |
    | The disk must be private. Never point this at "public".
    |
    */

    'filesystem' => [
        'disk' => env('RECORDING_STORAGE_DISK', 'local'),
        'root' => 'recordings',
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Drive Driver
    |--------------------------------------------------------------------------
    |
    | Credentials are NOT here — the service-account JSON, the delegated
    | Workspace account, the root folder and the optional Shared Drive id
    | are admin-managed (and encrypted) in MeetingSettings, alongside the
    | Google Meet configuration that uses the very same service account.
    | Only transfer mechanics live here.
    |
    | chunk_bytes must be a multiple of 256 KiB (Google's resumable
    | upload requirement) and bounds peak PHP memory during a transfer.
    |
    */

    'google_drive' => [
        'chunk_bytes' => (int) env('RECORDING_DRIVE_CHUNK_BYTES', 8 * 1024 * 1024),
        'request_timeout_seconds' => (int) env('RECORDING_DRIVE_TIMEOUT', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ingestion Staging
    |--------------------------------------------------------------------------
    |
    | Where a provider recording lands between download and upload.
    | Always LOCAL and always private (storage/app/private/<directory>):
    | staging is a working area a stream is written to and read back
    | from chunk by chunk, which only a real local filesystem can do —
    | it is deliberately not a configurable disk, so it can never be
    | pointed at the public disk or at remote storage by mistake.
    |
    | Files are deleted on success AND on failure; stale_hours backstops
    | a hard crash (recordings:capture purges anything older).
    |
    */

    'staging' => [
        'directory' => 'recording-ingestion',
        'stale_hours' => (int) env('RECORDING_STAGING_STALE_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transfer Safety Limits
    |--------------------------------------------------------------------------
    |
    | max_source_bytes is a hard ceiling on what the ingestion pipeline
    | will accept from a meeting provider — a corrupt or hostile
    | Content-Length can otherwise fill the staging disk. A source that
    | exceeds it fails permanently rather than retrying forever.
    |
    */

    'max_source_bytes' => (int) env('RECORDING_MAX_SOURCE_BYTES', 5 * 1024 * 1024 * 1024),

    /** Accepted stored content types — defence in depth against a malformed provider payload. */
    'allowed_mime_types' => [
        'video/mp4',
        'video/webm',
        'video/quicktime',
        'audio/mpeg',
        'audio/mp4',
        'audio/wav',
    ],

];
