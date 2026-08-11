<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps a Curriculum (identity established in Phase 0A: Subject +
 * AcademicLevel) into an EducationSystem it applies to. A Curriculum
 * with zero rows here is globally applicable / system-neutral (the
 * legacy-compatibility rule — see AcademicContextResolver). Duplicate
 * (curriculum_id, education_system_id) pairs are prevented by a DB
 * unique constraint and by EducationSystemService.
 */
class CurriculumEducationSystem extends Model
{
    use HasUuids;

    protected $table = 'curriculum_education_system';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'curriculum_id',
        'education_system_id',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (CurriculumEducationSystem $mapping): void {
            $mapping->created_by ??= auth()->id();
        });
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function educationSystem(): BelongsTo
    {
        return $this->belongsTo(EducationSystem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
