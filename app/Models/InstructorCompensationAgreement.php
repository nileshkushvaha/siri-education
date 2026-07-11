<?php

declare(strict_types=1);

namespace App\Models;

use App\Earnings\Enums\CompensationAgreementStatus;
use App\Earnings\Enums\CompensationPayBasis;
use Database\Factories\InstructorCompensationAgreementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An effective-dated instructor compensation agreement, decided and
 * managed exclusively by authorized administrators. The amount is an
 * internal business decision (experience, qualifications, subject
 * expertise inform the ADMIN — never a formula) and is fully
 * independent of student-facing pricing. Financial terms freeze at
 * activation; changes create a replacement agreement. All writes go
 * through InstructorCompensationAgreementService; never hard-deleted.
 */
class InstructorCompensationAgreement extends Model
{
    /** @use HasFactory<InstructorCompensationAgreementFactory> */
    use HasFactory, HasUuids, LogsActivity;

    /**
     * Creation fields only — status, approval/end/cancel stamps, and
     * version chaining are written by the service via forceFill after
     * guarding the transition.
     */
    protected $fillable = [
        'reference',
        'instructor_id',
        'pay_basis',
        'amount_minor',
        'currency_id',
        'currency_code',
        'timezone',
        'effective_from',
        'internal_reason',
        'notes',
        'created_by',
        'updated_by',
    ];

    /** Admin decision context — never serialized to instructors. */
    protected $hidden = [
        'internal_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'pay_basis' => CompensationPayBasis::class,
            'status' => CompensationAgreementStatus::class,
            'amount_minor' => 'integer',
            'version' => 'integer',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_agreement_id');
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(InstructorCompensationOverride::class, 'agreement_id');
    }

    public function periods(): HasMany
    {
        return $this->hasMany(InstructorCompensationPeriod::class, 'agreement_id');
    }

    public function scopeForInstructor(Builder $query, int $instructorId): Builder
    {
        return $query->where('instructor_id', $instructorId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CompensationAgreementStatus::Active);
    }

    /** Does this agreement's effective window cover the given instant? */
    public function coversInstant(\DateTimeInterface $at): bool
    {
        return $this->effective_from <= $at
            && ($this->effective_until === null || $this->effective_until > $at);
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Safe metadata only — never internal_reason/notes, never any
        // student-pricing value (none exists on this model by design).
        return LogOptions::defaults()
            ->logOnly(['status', 'pay_basis', 'amount_minor', 'currency_code', 'effective_from', 'effective_until'])
            ->useLogName('instructor_compensation')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
