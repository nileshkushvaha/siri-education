<?php

declare(strict_types=1);

namespace App\Models;

use App\Booking\Enums\MeetingStatus;
use Database\Factories\BookingMeetingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One meeting per booking (unique booking_id). Never stores raw
 * provider payloads — only a sanitized `metadata` snapshot.
 */
class BookingMeeting extends Model
{
    /** @use HasFactory<BookingMeetingFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'booking_id',
        'provider',
        'provider_meeting_id',
        'provider_event_id',
        'join_url',
        'host_url',
        'password',
        'starts_at',
        'ends_at',
        'timezone',
        'status',
        'failure_reason',
        'metadata',
        'created_by',
        'updated_by',
    ];

    /** host_url/password are provider-side secrets — never serialize by default. */
    protected $hidden = [
        'host_url',
        'password',
    ];

    protected function casts(): array
    {
        return [
            'status' => MeetingStatus::class,
            'metadata' => 'array',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
