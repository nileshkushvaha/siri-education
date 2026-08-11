<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps an EducationSystem into a Country (SRS country-specific
 * booking foundation). A mapping row, not a duplicate of either
 * master — mirrors CurriculumModuleTopic's shape. `is_active` lets an
 * admin temporarily withdraw a system from a country without deleting
 * the historical mapping row; `display_order` drives UI ordering.
 * Duplicate (country_id, education_system_id) pairs are prevented by
 * a DB unique constraint and by EducationSystemService.
 */
class CountryEducationSystem extends Model
{
    use HasUuids;

    protected $table = 'country_education_system';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'is_active' => true,
        'display_order' => 0,
    ];

    protected $fillable = [
        'country_id',
        'education_system_id',
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
        static::creating(function (CountryEducationSystem $mapping): void {
            $mapping->created_by ??= auth()->id();
        });
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function educationSystem(): BelongsTo
    {
        return $this->belongsTo(EducationSystem::class);
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
