<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Configurable settings for a booking type. `key` links the row to its
 * code driver (BookingTypeInterface) in BookingTypeRegistry — the row
 * holds tunable values (price, duration), the driver holds behavior.
 */
class BookingType extends Model
{
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'key',
        'name',
        'description',
        'duration_minutes',
        'max_attendees',
        'buffer_minutes',
        'requires_approval',
        'is_paid',
        'price',
        'currency',
        'is_active',
        'sort_order',
        'meta',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'max_attendees' => 'integer',
            'buffer_minutes' => 'integer',
            'requires_approval' => 'boolean',
            'is_paid' => 'boolean',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'meta' => 'array',
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'duration_minutes', 'max_attendees', 'requires_approval', 'is_paid', 'price', 'currency', 'is_active'])
            ->useLogName('booking_types')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
