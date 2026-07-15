<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subject (and inclusive grade range) a teacher can teach.
 * Null grade bounds mean the subject is taught at any grade.
 *
 * `subject` (free-text) is the field booking flows have always read
 * (AssignmentCriteriaData, WizardBookingData, StudentBookingData,
 * TeacherCandidateRepository) and continues to be — untouched by the
 * Subject reconciliation. `subject_id` is an optional link to the
 * Subject master, nullable for backward compatibility with rows that
 * predate it or whose free-text value didn't match a master by name.
 * See docs/architecture/subject-teacher-subject-reconciliation.md.
 */
class TeacherSubject extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'teacher_id',
        'subject',
        'subject_id',
        'grade_from',
        'grade_to',
    ];

    protected function casts(): array
    {
        return [
            'grade_from' => 'integer',
            'grade_to' => 'integer',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /** The linked Subject master, when this row has been reconciled to one. May be null. */
    public function subjectMaster(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function scopeForSubject(Builder $query, string $subject): Builder
    {
        return $query->where('subject', $subject);
    }

    public function scopeCoveringGrade(Builder $query, int $grade): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('grade_from')->orWhere('grade_from', '<=', $grade))
            ->where(fn (Builder $q) => $q->whereNull('grade_to')->orWhere('grade_to', '>=', $grade));
    }
}
