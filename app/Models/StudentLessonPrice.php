<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Admin-managed student-facing price for one (booking type, instructor,
 * subject, academic level, country, duration) combination — see
 * StudentLessonPriceResolver for the matching rules. `instructor_id`
 * is nullable: null means the base price (applies to every
 * instructor); set means a special student-facing price for that one
 * instructor only, which the resolver always prefers over the base
 * price. This is a *student-facing amount* override — it has nothing
 * to do with what the instructor is paid (no payout/earnings concept
 * exists yet, and this column doesn't introduce one). `amount_minor`
 * is the only source of truth for money on this row; never derive a
 * price from a float. Never expose this model (or any field of it) to
 * an instructor — see docs/architecture/phase-10.2d-student-pricing-matrix.md.
 */
class StudentLessonPrice extends Model
{
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'booking_type_id',
        'instructor_id',
        'subject_id',
        'academic_level_id',
        'country_id',
        'currency_id',
        'currency_code',
        'duration_minutes',
        'amount_minor',
        'is_active',
        'effective_from',
        'effective_until',
        'priority',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'amount_minor' => 'integer',
            'is_active' => 'boolean',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'priority' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StudentLessonPrice $price): void {
            $price->created_by ??= auth()->id();
            $price->updated_by = auth()->id();
        });

        static::updating(function (StudentLessonPrice $price): void {
            $price->updated_by = auth()->id();
        });
    }

    public function bookingType(): BelongsTo
    {
        return $this->belongsTo(BookingType::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function academicLevel(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Decimal display amount (e.g. 499.00) — for admin/UI display only; never used in money math. */
    public function amountDecimal(): float
    {
        return $this->amount_minor / (10 ** ($this->currency?->minor_units ?? 2));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeEffectiveNow(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $now));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['booking_type_id', 'instructor_id', 'subject_id', 'academic_level_id', 'country_id', 'currency_id', 'duration_minutes', 'amount_minor', 'is_active', 'effective_from', 'effective_until', 'priority'])
            ->useLogName('student_lesson_prices')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
