<?php

declare(strict_types=1);

namespace App\Models;

use App\Package\Enums\PackageEntitlementReservationStatus;
use App\Support\Concerns\PreventsHardDeletion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 4D — one entitlement unit committed to one future Booking.
 *
 * Unlike StudentPackageEntitlementConsumption (write-once evidence of
 * something that already happened), a reservation is a live commitment
 * that legitimately transitions Reserved → Released|Consumed, so it
 * uses PreventsHardDeletion WITHOUT PreventsUpdates. The updates it
 * permits are only those two transitions, and only
 * PackageEntitlementService performs them — no controller, Livewire
 * component, Filament resource, or policy grants create/update/delete
 * on this model (spec §41). Deletion is blocked outright because a
 * released reservation is auditable history, not garbage (spec §28).
 *
 * Capacity arithmetic (spec §19) — these are three different numbers
 * and must not be conflated:
 *
 *      remaining_quantity  = total - used        (DB generated column;
 *                            purchased units not yet CONSUMED)
 *      reserved_quantity   = COUNT(status=Reserved)
 *      available_to_book   = remaining - reserved
 *
 * `remaining_quantity` deliberately keeps its Phase 4A meaning and is
 * never rewritten to mean available capacity.
 */
class StudentPackageEntitlementReservation extends Model
{
    use HasUuids, PreventsHardDeletion;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'status' => 'reserved',
    ];

    protected $fillable = [
        'entitlement_id',
        'booking_id',
        'status',
        'reserved_at',
        'released_at',
        'consumed_at',
        'release_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => PackageEntitlementReservationStatus::class,
            'reserved_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(StudentPackageEntitlement::class, 'entitlement_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** The reservations that currently hold capacity — the exact set availableToBook() subtracts. */
    public function scopeHoldingCapacity(Builder $query): Builder
    {
        return $query->where('status', PackageEntitlementReservationStatus::Reserved);
    }

    public function scopeForBooking(Builder $query, string $bookingId): Builder
    {
        return $query->where('booking_id', $bookingId);
    }

    public function scopeForEntitlement(Builder $query, string $entitlementId): Builder
    {
        return $query->where('entitlement_id', $entitlementId);
    }
}
