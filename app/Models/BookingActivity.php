<?php

declare(strict_types=1);

namespace App\Models;

use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only lifecycle timeline for a booking (bigint PK, created_at
 * only). The unified activity_log remains the audit trail; this table
 * powers the per-booking history UI and reporting.
 */
class BookingActivity extends Model
{
    use HasFactory;

    public const null UPDATED_AT = null;

    protected $fillable = [
        'booking_id',
        'action',
        'actor_type',
        'actor_id',
        'status_from',
        'status_to',
        'meta',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => BookingActivityAction::class,
            'actor_type' => BookingActor::class,
            'status_from' => BookingStatus::class,
            'status_to' => BookingStatus::class,
            'meta' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function scopeForAction(Builder $query, BookingActivityAction $action): Builder
    {
        return $query->where('action', $action);
    }
}
