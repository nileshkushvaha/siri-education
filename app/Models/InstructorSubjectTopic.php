<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Explicit topic-level coverage: an instructor teaches this part of a
 * subject, optionally scoped to one academic level (null = all levels).
 * Coverage counts for booking/marketplace only when active AND
 * admin-approved (approved_at set) — see scopeBookable(). Coverage
 * never grants pricing visibility: student prices stay admin-only.
 */
class InstructorSubjectTopic extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'teacher_id',
        'subject_id',
        'subject_topic_id',
        'academic_level_id',
        'proficiency_level',
        'is_primary',
        'is_active',
        'approved_at',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'approved_at' => 'immutable_datetime',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(SubjectTopic::class, 'subject_topic_id');
    }

    public function academicLevel(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Coverage that counts for booking and marketplace matching. */
    public function scopeBookable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNotNull('approved_at');
    }

    /** Coverage whose level (when set) includes the given 1-12 grade; null level = all levels. */
    public function scopeCoveringGrade(Builder $query, ?int $grade): Builder
    {
        if ($grade === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->whereNull('academic_level_id')
            ->orWhereHas('academicLevel', fn (Builder $levelQuery) => $levelQuery
                ->where(fn (Builder $lq) => $lq->whereNull('min_grade')->orWhere('min_grade', '<=', $grade))
                ->where(fn (Builder $lq) => $lq->whereNull('max_grade')->orWhere('max_grade', '>=', $grade))));
    }
}
