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
    | Playback Delivery
    |--------------------------------------------------------------------------
    |
    | Student playback is proxied through the application, so every byte
    | a viewer watches passes through one PHP worker. A browser's first
    | media request is `Range: bytes=0-` — the WHOLE object — and if it
    | were honoured literally that worker would stay occupied for the
    | entire viewing session, trickling bytes as fast as the player
    | consumes them. max_range_bytes caps how much of a single ranged
    | response is served (RFC 9110 permits a 206 to enclose less than was
    | asked; the player simply asks again), so worker occupancy per
    | request is bounded by transfer time of this many bytes, not by the
    | length of the lesson. chunk_bytes bounds peak memory per request.
    |
    */

    'playback' => [
        'max_range_bytes' => (int) env('RECORDING_PLAYBACK_MAX_RANGE_BYTES', 8 * 1024 * 1024),
        'chunk_bytes' => (int) env('RECORDING_PLAYBACK_CHUNK_BYTES', 512 * 1024),
        // The viewer watermark is repositioned this often (a
        // redistribution deterrent, not DRM); 0 keeps it still.
        'watermark_move_seconds' => (int) env('RECORDING_WATERMARK_MOVE_SECONDS', 12),
        // Repeated refusals of the same viewer for the same recording
        // inside this window go to the application log, not the audit
        // table — the first one is the durable record.
        'denial_audit_window_seconds' => (int) env('RECORDING_DENIAL_AUDIT_WINDOW_SECONDS', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Zoom Cloud Recording
    |--------------------------------------------------------------------------
    |
    | Credentials are NOT here — the Server-to-Server OAuth client and
    | the webhook secret are admin-managed (and encrypted) in
    | MeetingSettings alongside the rest of the Zoom configuration.
    | Only transfer mechanics live here.
    |
    | preferred_layouts orders which Zoom video layout is ingested when a
    | meeting produced more than one. Zoom can emit several MP4s for the
    | same meeting (speaker view, gallery view, shared screen variants),
    | so an explicit, documented order is what stops SIRI from silently
    | taking whichever the API happened to list first.
    |
    */

    'zoom' => [
        'download_timeout' => (int) env('RECORDING_ZOOM_DOWNLOAD_TIMEOUT', 900),
        'preferred_layouts' => [
            'shared_screen_with_speaker_view',
            'shared_screen_with_gallery_view',
            'active_speaker',
            'gallery_view',
            'shared_screen',
            'speaker_view',
        ],
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
