<?php

declare(strict_types=1);

namespace App\Models;

use App\Booking\Enums\RecordingFailureCode;
use App\Booking\Enums\RecordingStatus;
use Database\Factories\RecordingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SRS §12.18-21/31: durable lesson-recording metadata and the CANONICAL
 * record of one lesson recording.
 *
 * The bytes are not here. They live wherever the row's storage locator
 * (storage_driver + storage_path) points — Google Drive today, an S3
 * disk later — reached only through the RecordingStorage abstraction.
 * This model exposes no backend-specific accessor on purpose: there is
 * no google_drive_file_id, no drive URL, no signed link. Business
 * logic, policies, notifications and UI all work from this row and
 * from RecordingStorage, so replacing the backend touches neither.
 *
 * duration/size/mime_type/checksum are stored ON THIS ROW rather than
 * read from the storage backend so they survive the object's deletion
 * at retention expiry — metadata must outlive the file (SRS §12.21).
 *
 * No LogsActivity trait — RecordingService writes every lifecycle
 * event through AuditTrailService exclusively (CLAUDE.md's audit rule
 * is authoritative project-wide), so a passive trait hook here would
 * only produce duplicate entries.
 */
class Recording extends Model
{
    /** @use HasFactory<RecordingFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'booking_meeting_id',
        'booking_id',
        'student_id',
        'teacher_id',
        'provider',
        'provider_reference',
        'storage_driver',
        'storage_path',
        'storage_checksum',
        'status',
        'idempotency_key',
        'consent_snapshot',
        'duration_seconds',
        'size_bytes',
        'mime_type',
        'capture_attempts',
        'failure_code',
        'recorded_at',
        'transfer_started_at',
        'stored_at',
        'available_at',
        'failed_at',
        'expires_at',
        'student_access_revoked_at',
        'student_access_revoked_by',
    ];

    /**
     * The storage locator is infrastructure detail, not something an
     * API response or a Livewire payload should ever carry — a Drive
     * file id is not secret, but publishing it invites exactly the
     * out-of-band access this architecture exists to prevent.
     */
    protected $hidden = [
        'storage_driver',
        'storage_path',
        'storage_checksum',
    ];

    protected function casts(): array
    {
        return [
            'status' => RecordingStatus::class,
            'failure_code' => RecordingFailureCode::class,
            'consent_snapshot' => 'array',
            'duration_seconds' => 'integer',
            'size_bytes' => 'integer',
            'capture_attempts' => 'integer',
            'recorded_at' => 'immutable_datetime',
            'transfer_started_at' => 'immutable_datetime',
            'stored_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'student_access_revoked_at' => 'immutable_datetime',
        ];
    }

    public function bookingMeeting(): BelongsTo
    {
        return $this->belongsTo(BookingMeeting::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /** The administrator who withheld student access, when one has. */
    public function studentAccessRevokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_access_revoked_by');
    }

    /** Whether a viewer could be served this recording's content right now. */
    public function isPlayable(): bool
    {
        return $this->status === RecordingStatus::Available
            && $this->storage_driver !== null
            && $this->storage_path !== null;
    }

    /**
     * A per-recording administrative exception to the student playback
     * policy (SRS §12.20). Orthogonal to the ingestion lifecycle: a
     * withheld recording is still Available to administrators, still
     * verified, still subject to retention.
     */
    public function isStudentAccessWithheld(): bool
    {
        return $this->student_access_revoked_at !== null;
    }

    /**
     * Awaiting a first (or retried) ingestion attempt. Stored is
     * included because a run interrupted between upload and
     * verification must be resumed, not abandoned — see
     * RecordingIngestionService.
     */
    public function scopeDueForCapture(Builder $query): Builder
    {
        return $query->whereIn('status', [RecordingStatus::Pending, RecordingStatus::Stored]);
    }

    /**
     * Claimed by a worker that never finished — a crash, an OOM kill,
     * or a lost queue worker. The sweep returns these to Pending once
     * they are older than the configured stale threshold.
     */
    public function scopeStalledInTransfer(Builder $query, int $staleMinutes): Builder
    {
        return $query->where('status', RecordingStatus::Transferring)
            ->where('transfer_started_at', '<=', now()->subMinutes(max(1, $staleMinutes)));
    }

    public function scopeDueForExpiry(Builder $query): Builder
    {
        return $query->where('status', RecordingStatus::Available)
            ->where('expires_at', '<=', now());
    }
}
