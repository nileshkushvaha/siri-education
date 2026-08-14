<?php

declare(strict_types=1);

namespace App\Models;

use App\Package\Enums\PackageEntitlementStatus;
use App\Support\Concerns\PreventsHardDeletion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Phase 4A — what a student actually owns after accepting an approved
 * InstructorPackageProposal: a drawn-down balance of lessons. See
 * docs/architecture/domain-registry.md "Personalized Packages".
 *
 * Deliberately separate from InstructorPackageProposal: the proposal is
 * the commercial record (price, approval, override) and is immutable
 * once Accepted; this is the consumable balance that changes every time
 * a lesson is used. One entitlement per proposal, enforced by a UNIQUE
 * index on proposal_id — duplicate acceptance is impossible at the DB.
 *
 * `remaining_quantity` is a STORED GENERATED column (total - used) and
 * is therefore intentionally absent from $fillable: it cannot be
 * written by anything, so it can never drift. Read it after a write via
 * refresh() — PackageEntitlementService::consumeLesson() does exactly
 * that and is the ONLY thing that may change used_quantity.
 *
 * PreventsHardDeletion (no SoftDeletes): a student's owned balance is
 * historical financial-adjacent value — it is ended by status, never
 * deleted.
 */
class StudentPackageEntitlement extends Model
{
    use HasUuids, LogsActivity, PreventsHardDeletion;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'used_quantity' => 0,
        'status' => 'active',
    ];

    /** remaining_quantity is deliberately excluded — it is DB-generated and unwritable. */
    protected $fillable = [
        'student_id',
        'instructor_id',
        'proposal_id',
        'subject_id',
        'academic_level_id',
        'booking_type_id',
        'paid_quantity',
        'bonus_quantity',
        'total_quantity',
        'validity_days',
        'used_quantity',
        'status',
        'activated_at',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PackageEntitlementStatus::class,
            'paid_quantity' => 'integer',
            'bonus_quantity' => 'integer',
            'total_quantity' => 'integer',
            'validity_days' => 'integer',
            'used_quantity' => 'integer',
            'remaining_quantity' => 'integer',
            'activated_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(InstructorPackageProposal::class, 'proposal_id');
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

    /**
     * Phase 4D — units committed to future bookings. Capacity is
     * counted through PackageEntitlementService::availableToBook(),
     * never by reading this relation's size in business code.
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(StudentPackageEntitlementReservation::class, 'entitlement_id');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(StudentPackageEntitlementConsumption::class, 'entitlement_id');
    }

    /**
     * Phase 4D — the package's structured academic identity, reached
     * THROUGH the proposal rather than copied onto every entitlement
     * (spec §2: one academic truth per package, not four).
     */
    public function academicContext(): HasOneThrough
    {
        return $this->hasOneThrough(
            PackageAcademicContext::class,
            InstructorPackageProposal::class,
            'id',           // proposals.id
            'proposal_id',  // package_academic_contexts.proposal_id
            'proposal_id',  // entitlements.proposal_id
            'id',           // proposals.id
        );
    }

    public function scopeForStudent(Builder $query, int $studentId): Builder
    {
        return $query->where('student_id', $studentId);
    }

    public function scopeForInstructor(Builder $query, int $instructorId): Builder
    {
        return $query->where('instructor_id', $instructorId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', PackageEntitlementStatus::Active);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'used_quantity'])
            ->useLogName('student_package_entitlements')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
