<?php

declare(strict_types=1);

namespace App\Models;

use App\Booking\Enums\MeetingAttendanceProcessingStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A received meeting-attendance provider event (webhook envelope or
 * sync pull) — the operational record behind ingestion dedup, bounded
 * retries, and human review. Deliberately stores NO raw payload:
 * normalized_events holds only sanitized, participant-hashed
 * ProviderAttendanceEvent data. Unique (provider, provider_event_id)
 * makes replayed webhooks duplicates, not double ingestion.
 */
class MeetingAttendanceProviderEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'provider',
        'provider_event_id',
        'meeting_reference',
        'booking_meeting_id',
        'lesson_id',
        'source',
        'signature_valid',
        'processing_status',
        'status_reason',
        'normalized_events',
        'results',
        'attempts',
        'received_at',
        'processed_at',
        'duplicate_of_id',
    ];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'processing_status' => MeetingAttendanceProcessingStatus::class,
            'normalized_events' => 'array',
            'results' => 'array',
            'attempts' => 'integer',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(BookingMeeting::class, 'booking_meeting_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }
}
