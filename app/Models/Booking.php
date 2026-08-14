<?php

declare(strict_types=1);

namespace App\Models;

use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingLocationType;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\RecurrenceFrequency;
use App\Support\Concerns\PreventsHardDeletion;
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
    use HasFactory, HasUuids, LogsActivity, PreventsHardDeletion, SoftDeletes;

    protected $fillable = [
        'reference',
        'booking_type_id',
        'student_id',
        'instructor_id',
        'status',
        'payment_status',
        'location_type',
        'starts_at',
        'ends_at',
        'timezone',
        'price',
        'currency',
        'payment_reference',
        'package_entitlement_id',
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
        'recurrence_frequency',
        'created_by',
    ];

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
            'recurrence_frequency' => RecurrenceFrequency::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Booking $booking): void {
            $booking->reference ??= 'BK-'.strtoupper(Str::random(10));
        });
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(BookingType::class, 'booking_type_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function meeting(): HasOne
    {
        return $this->hasOne(BookingMeeting::class);
    }

    public function lesson(): HasOne
    {
        return $this->hasOne(Lesson::class);
    }

    /**
     * Phase 3 — the immutable academic-context snapshot, present only
     * for country-aware Free Demo bookings created while
     * CountryFeature::CountryAcademicBooking was enabled for the
     * student's country. Null for every legacy/paid/non-academic
     * booking — callers must handle the null case, never assume it
     * exists.
     */
    public function academicContext(): HasOne
    {
        return $this->hasOne(BookingAcademicContext::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BookingPayment::class);
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

    public function scopeForInstructor(Builder $query, int $instructorId): Builder
    {
        return $query->where('instructor_id', $instructorId);
    }

    public function scopeForStudent(Builder $query, int $studentId): Builder
    {
        return $query->where('student_id', $studentId);
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
            ->logOnly(['status', 'payment_status', 'starts_at', 'ends_at', 'instructor_id', 'student_id'])
            ->useLogName('bookings')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
