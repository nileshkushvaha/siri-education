<?php

declare(strict_types=1);

namespace App\Models;

use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingLocationType;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Booking extends Model
{
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'reference',
        'booking_type_id',
        'attendee_id',
        'guest_name',
        'guest_email',
        'guest_phone',
        'manage_token',
        'host_id',
        'status',
        'payment_status',
        'location_type',
        'starts_at',
        'ends_at',
        'timezone',
        'price',
        'currency',
        'payment_reference',
        'reserved_until',
        'meeting_provider',
        'meeting_ref',
        'meeting_url',
        'cancelled_by',
        'cancellation_reason',
        'confirmed_at',
        'cancelled_at',
        'completed_at',
        'notes',
        'meta',
        'created_by',
    ];

    /** The capability token is a credential — never serialize it. */
    protected $hidden = [
        'manage_token',
    ];

    /**
     * The un-hashed guest token, populated ONLY on the model instance
     * returned from BookingRepository::create(). Never persisted —
     * the database stores its SHA-256 hash.
     */
    public ?string $plainManageToken = null;

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'payment_status' => BookingPaymentStatus::class,
            'location_type' => BookingLocationType::class,
            'cancelled_by' => BookingActor::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'reserved_until' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'price' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Booking $booking): void {
            $booking->reference ??= 'BK-'.strtoupper(Str::random(10));
        });
    }

    public function isGuest(): bool
    {
        return $this->attendee_id === null;
    }

    /** Display name of the booked party, user or guest. */
    public function attendeeName(): ?string
    {
        return $this->attendee?->name ?? $this->guest_name;
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(BookingType::class, 'booking_type_id');
    }

    public function attendee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendee_id');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function guests(): HasMany
    {
        return $this->hasMany(BookingGuest::class);
    }

    public function meeting(): HasOne
    {
        return $this->hasOne(BookingMeeting::class);
    }

    public function lesson(): HasOne
    {
        return $this->hasOne(Lesson::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(BookingActivity::class)->latest('created_at');
    }

    /** Bookings still in play: pending or confirmed. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed]);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('starts_at', '>=', now());
    }

    public function scopePast(Builder $query): Builder
    {
        return $query->where('ends_at', '<', now());
    }

    public function scopeForHost(Builder $query, int $hostId): Builder
    {
        return $query->where('host_id', $hostId);
    }

    public function scopeForAttendee(Builder $query, int $attendeeId): Builder
    {
        return $query->where('attendee_id', $attendeeId);
    }

    public function scopeWithStatus(Builder $query, BookingStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /** Active bookings intersecting [$startsAt, $endsAt). */
    public function scopeOverlapping(
        Builder $query,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?string $ignoreBookingId = null,
    ): Builder {
        return $query
            ->active()
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->when($ignoreBookingId, fn (Builder $q) => $q->whereKeyNot($ignoreBookingId));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'payment_status', 'starts_at', 'ends_at', 'host_id', 'attendee_id'])
            ->useLogName('bookings')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
