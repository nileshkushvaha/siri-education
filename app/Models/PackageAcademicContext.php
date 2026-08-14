<?php

declare(strict_types=1);

namespace App\Models;

use App\Booking\DTOs\BookingAcademicContextData;
use App\Support\Concerns\PreventsHardDeletion;
use App\Support\Concerns\PreventsUpdates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 4D — the immutable structured academic identity of one
 * personalized package proposal, frozen at submission.
 *
 * Immutable by construction, exactly like BookingAcademicContext:
 * PreventsUpdates blocks every field change after creation and
 * PreventsHardDeletion blocks delete(), so a package's academic
 * identity cannot drift after an admin has approved it or a student has
 * paid for it. There is no admin CRUD for this model — if a submitted
 * proposal's context is wrong, the correct remedy is to reject it and
 * require a new proposal (spec §14), not to mutate a historical offer.
 *
 * The denormalized display columns (names, codes, level label, version
 * number) are the authoritative historical record; the id columns are
 * best-effort links that go null if a master is later hard-deleted.
 * This is what makes "package bought under Curriculum v2 stays v2 after
 * v3 is published" true by construction rather than by convention.
 *
 * Purchases and entitlements deliberately do NOT copy this snapshot —
 * they reach it through the proposal (see
 * StudentPackageEntitlement::academicContext()), so there is exactly
 * one academic truth per package.
 */
class PackageAcademicContext extends Model
{
    use HasUuids, PreventsHardDeletion, PreventsUpdates;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'proposal_id',
        'country_id',
        'country_code',
        'country_name',
        'education_system_id',
        'education_system_code',
        'education_system_name',
        'academic_level_id',
        'academic_level_name',
        'education_system_level_id',
        'level_term',
        'level_value',
        'level_display',
        'normalized_grade',
        'subject_id',
        'subject_name',
        'subject_slug',
        'curriculum_id',
        'curriculum_name',
        'curriculum_slug',
        'curriculum_version_id',
        'curriculum_version_number',
    ];

    protected function casts(): array
    {
        return [
            'curriculum_version_number' => 'integer',
            'normalized_grade' => 'integer',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(InstructorPackageProposal::class, 'proposal_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function educationSystem(): BelongsTo
    {
        return $this->belongsTo(EducationSystem::class);
    }

    public function academicLevel(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class);
    }

    public function educationSystemLevel(): BelongsTo
    {
        return $this->belongsTo(EducationSystemLevel::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    /**
     * The frozen context as the shared snapshot DTO — the exact value a
     * package-funded Booking's own BookingAcademicContext is built from
     * (spec §38), so a booking can never be silently upgraded onto a
     * newer CurriculumVersion than the package was sold under.
     */
    public function toSnapshotData(): BookingAcademicContextData
    {
        return new BookingAcademicContextData(
            countryId: (int) $this->country_id,
            countryCode: $this->country_code,
            countryName: (string) $this->country_name,
            educationSystemId: (string) $this->education_system_id,
            educationSystemCode: $this->education_system_code,
            educationSystemName: (string) $this->education_system_name,
            academicLevelId: (string) $this->academic_level_id,
            academicLevelName: (string) $this->academic_level_name,
            educationSystemLevelId: (string) $this->education_system_level_id,
            levelTerm: (string) $this->level_term,
            levelValue: (string) $this->level_value,
            levelDisplay: (string) $this->level_display,
            normalizedGrade: $this->normalized_grade,
            subjectId: (string) $this->subject_id,
            subjectName: (string) $this->subject_name,
            subjectSlug: $this->subject_slug,
            curriculumId: (string) $this->curriculum_id,
            curriculumName: (string) $this->curriculum_name,
            curriculumSlug: $this->curriculum_slug,
            curriculumVersionId: (string) $this->curriculum_version_id,
            curriculumVersionNumber: (int) $this->curriculum_version_number,
        );
    }
}
