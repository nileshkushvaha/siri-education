<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\ImmutableRecordCannotBeUpdatedException;
use App\Package\Enums\InstructorPackageProposalStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Personalized Instructor Package Proposal — an instructor-created,
 * admin-approved proposal to sell an existing student a personalized
 * lesson package. See docs/architecture/domain-registry.md
 * "Personalized Packages" and App\Package\Services\
 * InstructorPackageProposalService (the sole writer of every state
 * transition and every price/quantity field on this model).
 *
 * Carries its own immutable price snapshot (unit/calculated/override/
 * final_price_minor, all in the student's resolved currency, locked
 * at submission — never admin-editable) and quantity snapshot
 * (paid/bonus/total_quantity, copied from PackageBenefitRule at
 * submission time) so a later admin edit to the rule or the student's
 * currency never rewrites an already-submitted proposal's numbers.
 *
 * Immutable once Accepted: unlike PreventsUpdates (which blocks EVERY
 * post-create write and would make the Draft->Submitted->Approved
 * progression impossible), this model only refuses updates once its
 * ORIGINAL (pre-update) status is Accepted — every earlier transition
 * legitimately mutates the row.
 */
class InstructorPackageProposal extends Model
{
    use HasUuids, LogsActivity;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'status' => 'draft',
    ];

    protected $fillable = [
        'instructor_id',
        'student_id',
        'package_benefit_rule_id',
        'subject_id',
        'academic_level_id',
        'education_system_id',
        'education_system_level_id',
        'booking_type_id',
        'duration_minutes',
        'country_id',
        'currency_id',
        'currency_code',
        'unit_price_minor',
        'paid_quantity',
        'bonus_quantity',
        'total_quantity',
        'validity_days',
        'calculated_price_minor',
        'override_price_minor',
        'final_price_minor',
        'overridden_by',
        'overridden_at',
        'override_reason',
        'status',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'rejection_reason',
        'accepted_at',
        'cancelled_at',
        'expires_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => InstructorPackageProposalStatus::class,
            'duration_minutes' => 'integer',
            'unit_price_minor' => 'integer',
            'paid_quantity' => 'integer',
            'bonus_quantity' => 'integer',
            'total_quantity' => 'integer',
            'validity_days' => 'integer',
            'calculated_price_minor' => 'integer',
            'override_price_minor' => 'integer',
            'final_price_minor' => 'integer',
            'overridden_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (InstructorPackageProposal $proposal): void {
            $proposal->created_by ??= auth()->id();
            $proposal->updated_by ??= auth()->id();
        });

        static::updating(function (InstructorPackageProposal $proposal): void {
            if ($proposal->getOriginal('status') === InstructorPackageProposalStatus::Accepted) {
                throw new ImmutableRecordCannotBeUpdatedException(sprintf(
                    'InstructorPackageProposal #%s is Accepted and cannot be updated further.',
                    $proposal->getKey(),
                ));
            }

            $proposal->updated_by = auth()->id();
        });
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function packageBenefitRule(): BelongsTo
    {
        return $this->belongsTo(PackageBenefitRule::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function academicLevel(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class);
    }

    public function bookingType(): BelongsTo
    {
        return $this->belongsTo(BookingType::class);
    }

    public function educationSystem(): BelongsTo
    {
        return $this->belongsTo(EducationSystem::class);
    }

    public function educationSystemLevel(): BelongsTo
    {
        return $this->belongsTo(EducationSystemLevel::class);
    }

    /**
     * Phase 4D — the frozen structured academic identity of this
     * package, written once at submission and immutable thereafter.
     * Null for a legacy proposal created while the packages feature was
     * off for the student's country; such a proposal is deliberately
     * ineligible for the structured package-funded booking path.
     */
    public function academicContext(): HasOne
    {
        return $this->hasOne(PackageAcademicContext::class, 'proposal_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeForInstructor(Builder $query, int $instructorId): Builder
    {
        return $query->where('instructor_id', $instructorId);
    }

    public function scopeForStudent(Builder $query, int $studentId): Builder
    {
        return $query->where('student_id', $studentId);
    }

    public function scopeVisibleToStudent(Builder $query): Builder
    {
        return $query->whereIn('status', [
            InstructorPackageProposalStatus::Approved->value,
            InstructorPackageProposalStatus::Accepted->value,
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'final_price_minor', 'override_price_minor'])
            ->useLogName('instructor_package_proposals')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
