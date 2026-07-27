<?php

declare(strict_types=1);

namespace App\Models;

use App\Homework\Enums\HomeworkResourceStatus;
use Database\Factories\HomeworkResourceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SRS-7-8: an instructor's personal, reusable teaching
 * resource — categorized (subject/level), searchable, and versioned via
 * HomeworkResourceVersion. Distinct from HomeworkAssignment's own direct
 * attachments, which remain one-off and untouched.
 */
class HomeworkResource extends Model
{
    /** @use HasFactory<HomeworkResourceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'instructor_id',
        'title',
        'description',
        'subject_id',
        'academic_level_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => HomeworkResourceStatus::class,
        ];
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

    public function versions(): HasMany
    {
        return $this->hasMany(HomeworkResourceVersion::class)->orderByDesc('version_number');
    }

    public function latestVersion(): ?HomeworkResourceVersion
    {
        return $this->versions()->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', HomeworkResourceStatus::Active);
    }

    public function scopeForInstructor(Builder $query, int $instructorId): Builder
    {
        return $query->where('instructor_id', $instructorId);
    }
}
