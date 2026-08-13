<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\PreventsHardDeletion;
use App\Support\Concerns\PreventsUpdates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 3 — the immutable, historical academic-context snapshot for a
 * single country-aware Free Demo Booking (one row per Booking, created
 * exactly once, atomically, inside the same transaction as the Booking
 * itself — see CreateBookingAction). Preferred over stuffing this into
 * `bookings.meta` because the data is structured, queryable/reportable
 * (indexed master ids), and must never be silently overwritten by a
 * later meta write for the same booking.
 *
 * Stable references (country_id, education_system_id, academic_level_id,
 * subject_id, curriculum_id, curriculum_version_id) travel alongside
 * denormalized, human-readable display values (name/code/slug/version
 * number) captured at booking time — a later rename/republish of any
 * academic master must not alter what this Booking historically
 * represented. The denormalized columns are therefore the authoritative
 * historical record; the id columns are best-effort links for reporting
 * and go null (never cascade-delete this row) if the referenced master
 * is later hard-deleted.
 *
 * Immutable by construction: no SoftDeletes (there is no legitimate
 * "soft delete" of a historical snapshot — it lives or it doesn't),
 * PreventsHardDeletion blocks delete() entirely, PreventsUpdates blocks
 * every field change after creation. No admin CRUD editing exists for
 * this model. A legacy Booking (pre-Phase-3, or created while the
 * country-academic feature was disabled for its country) simply has no
 * row here — Booking::academicContext() is nullable by design.
 *
 * Phase 3.1: education_system_level_id/level_term/level_value/
 * level_display/normalized_grade replace the old hardcoded
 * academic_level_grade ("Grade %d") string — the student-facing
 * "Class 10"/"Grade 10"/"Year 10" selection, denormalized at booking
 * time so a later admin rename of the EducationSystemLevel's label
 * never rewrites this Booking's historical display (§40).
 */
class BookingAcademicContext extends Model
{
    use HasUuids, PreventsHardDeletion, PreventsUpdates;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'booking_id',
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

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
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
}
