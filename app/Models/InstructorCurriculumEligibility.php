<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\PreventsHardDeletion;
use Database\Factories\InstructorCurriculumEligibilityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Instructor Academic Eligibility (Phase 2): an admin-approved fact
 * that Instructor X may teach Curriculum Y under Education System Z.
 *
 * Anchors to Curriculum IDENTITY, not CurriculumVersion — a curriculum
 * being revised from v1 to v2 must never silently revoke an
 * instructor's qualification. Runtime "is this instructor bookable
 * right now" additionally requires a currently-Published
 * CurriculumVersion to exist, but that is resolved dynamically by
 * AcademicContextResolver/InstructorAcademicEligibilityResolver, never
 * stored here.
 *
 * education_system_id is always explicit on this row — never inferred
 * from Curriculum::educationSystemMappings() — because a system-neutral
 * (globally applicable) Curriculum may be independently approved under
 * more than one Education System for the same instructor (e.g.
 * Instructor A + Global Math Curriculum + CBSE, and separately
 * Instructor A + Global Math Curriculum + IB).
 *
 * Deliberately carries no country_id: instructor eligibility is
 * country-independent by design (student Country only selects which
 * Education Systems are *offered*, validated separately by
 * AcademicContextResolver) — see InstructorAcademicEligibilityService's
 * class docblock.
 *
 * Lifecycle is a single active/inactive flag, mirroring
 * InstructorSubjectTopic (no Draft/Submitted/Suspended workflow).
 * Never hard-deleted (PreventsHardDeletion, no SoftDeletes — this
 * model has no delete() path at all in application code; deactivation
 * is the only supported removal), so historical eligibility remains
 * auditable even after the row is switched off.
 */
class InstructorCurriculumEligibility extends Model
{
    /** @use HasFactory<InstructorCurriculumEligibilityFactory> */
    use HasFactory, HasUuids, PreventsHardDeletion;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'teacher_id',
        'education_system_id',
        'curriculum_id',
        'is_active',
        'notes',
        'approved_at',
        'approved_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'approved_at' => 'immutable_datetime',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function educationSystem(): BelongsTo
    {
        return $this->belongsTo(EducationSystem::class);
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Currently bookable-relevant eligibility: active only. Published-version currency is validated separately by the resolver. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
