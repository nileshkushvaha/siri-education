<?php

declare(strict_types=1);

namespace App\Models;

use App\Booking\Enums\MeetingStatus;
use App\Support\Concerns\PreventsHardDeletion;
use Database\Factories\BookingMeetingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One meeting per booking (unique booking_id). Never stores raw
 * provider payloads — only a sanitized `metadata` snapshot.
 *
 * Meeting history is booking history — never hard-deleted;
 * cancellation transitions `status` instead. See PreventsHardDeletion.
 *
 * TZ-1 — `booking_meetings.timezone` inherits the booking's
 * origin snapshot and additionally serves as the PROVIDER TIMEZONE
 * LABEL sent to Zoom and Google Calendar. It is not a viewer display
 * timezone (see Booking's class docblock). It also cannot shift the
 * meeting: Zoom receives `start_time` as a UTC `…Z` timestamp and
 * Google receives an offset-bearing ISO-8601 `dateTime`, so in both
 * APIs the instant is fixed by the timestamp and this label only
 * affects how the provider renders it.
 */
class BookingMeeting extends Model
{
    /** @use HasFactory<BookingMeetingFactory> */
    use HasFactory, HasUuids, PreventsHardDeletion;

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
        'attendance_synced_at',
        'attendance_sync_attempts',
        'attendance_sync_status',
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
            'attendance_synced_at' => 'immutable_datetime',
            'attendance_sync_attempts' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** The one recording registered for this meeting, when eligible (recordings.idempotency_key = recording:<id>). */
    public function recording(): HasOne
    {
        return $this->hasOne(Recording::class);
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
