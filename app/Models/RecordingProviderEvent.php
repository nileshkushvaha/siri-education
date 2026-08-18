<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One received recording webhook, kept as an operational record and as
 * the durable replay guard (unique provider + provider_event_id).
 *
 * Deliberately holds no payload, no download URL and no token: a
 * recording webhook carries short-lived credentials, and this table
 * exists to answer "have we seen this event?", not to archive it.
 */
class RecordingProviderEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'provider',
        'provider_event_id',
        'event_type',
        'meeting_reference',
        'booking_meeting_id',
        'recording_id',
        'processing_status',
        'status_reason',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function bookingMeeting(): BelongsTo
    {
        return $this->belongsTo(BookingMeeting::class);
    }

    public function recording(): BelongsTo
    {
        return $this->belongsTo(Recording::class);
    }
}
