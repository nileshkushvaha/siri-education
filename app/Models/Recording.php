<?php

declare(strict_types=1);

namespace App\Models;

use App\Booking\Enums\RecordingStatus;
use Database\Factories\RecordingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * SRS §12.18-21/31: durable lesson-recording metadata. The
 * file itself lives in the private 'file' Media Library collection
 * (never a public disk, never a public URL); duration/size/mime_type
 * are ALSO stored directly on this row (not read only from the Media
 * row) so they survive past the media file's deletion at retention
 * expiry — see RecordingService::expireDueRecordings().
 *
 * No LogsActivity trait — RecordingService writes every lifecycle
 * event through AuditTrailService exclusively (CLAUDE.md's audit rule
 * is authoritative project-wide), so a passive trait hook here would
 * only produce duplicate entries.
 */
class Recording extends Model implements HasMedia
{
    /** @use HasFactory<RecordingFactory> */
    use HasFactory, HasUuids, InteractsWithMedia;

    protected $fillable = [
        'booking_meeting_id',
        'booking_id',
        'student_id',
        'teacher_id',
        'provider',
        'provider_reference',
        'status',
        'idempotency_key',
        'consent_snapshot',
        'duration_seconds',
        'size_bytes',
        'mime_type',
        'capture_attempts',
        'failure_code',
        'recorded_at',
        'available_at',
        'failed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RecordingStatus::class,
            'consent_snapshot' => 'array',
            'duration_seconds' => 'integer',
            'size_bytes' => 'integer',
            'capture_attempts' => 'integer',
            'recorded_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        // Defense in depth only — content is always system-fetched via
        // a MeetingRecordingProviderInterface adapter, never a raw
        // end-user upload, but a real content-type restriction is still
        // cheap insurance against a malformed/corrupt provider payload.
        $this->addMediaCollection('file')
            ->useDisk('local')
            ->singleFile()
            ->acceptsMimeTypes(['video/mp4', 'video/webm', 'video/quicktime', 'audio/mpeg', 'audio/mp4', 'audio/wav']);
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

    public function scopeDueForCapture(Builder $query): Builder
    {
        return $query->where('status', RecordingStatus::Pending);
    }

    public function scopeDueForExpiry(Builder $query): Builder
    {
        return $query->where('status', RecordingStatus::Available)
            ->where('expires_at', '<=', now());
    }
}
