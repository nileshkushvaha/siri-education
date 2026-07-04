<?php

declare(strict_types=1);

namespace App\Models;

use App\Booking\Enums\BookingGuestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An additional participant on a booking (second parent, webinar
 * attendee). May be a registered user or an email-only invitee.
 */
class BookingGuest extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'booking_id',
        'user_id',
        'name',
        'email',
        'status',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BookingGuestStatus::class,
            'responded_at' => 'immutable_datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', BookingGuestStatus::Confirmed);
    }
}
