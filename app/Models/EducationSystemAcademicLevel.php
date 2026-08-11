<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps an AcademicLevel into an EducationSystem. The same AcademicLevel
 * row may be mapped into several systems — this is a mapping row, not
 * a duplicate level. Duplicate (education_system_id, academic_level_id)
 * pairs are prevented by a DB unique constraint and by
 * EducationSystemService.
 */
class EducationSystemAcademicLevel extends Model
{
    use HasUuids;

    protected $table = 'education_system_academic_level';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'is_active' => true,
        'display_order' => 0,
    ];

    protected $fillable = [
        'education_system_id',
        'academic_level_id',
        'is_active',
        'display_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EducationSystemAcademicLevel $mapping): void {
            $mapping->created_by ??= auth()->id();
        });
    }

    public function educationSystem(): BelongsTo
    {
        return $this->belongsTo(EducationSystem::class);
    }

    public function academicLevel(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
