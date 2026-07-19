<?php

declare(strict_types=1);

namespace App\Models;

use App\Homework\Enums\HomeworkStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HomeworkAssignment extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'booking_id',
        'learning_plan_id',
        'teacher_id',
        'student_id',
        'subject',
        'title',
        'description',
        'due_at',
        'status',
        'submission_text',
        'submitted_at',
        'grade',
        'feedback',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'status' => HomeworkStatus::class,
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Phase 24J — GAP-021: resolves soft-deleted plans so historical
     * homework context stays visible after a plan is archived/removed.
     */
    public function learningPlan(): BelongsTo
    {
        return $this->belongsTo(StudentLearningPlan::class, 'learning_plan_id')->withTrashed();
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /** Phase 24K — GAP-020: claimed/dispatched due-date reminder history. */
    public function dueReminders(): HasMany
    {
        return $this->hasMany(HomeworkDueReminder::class, 'homework_assignment_id');
    }

    public function scopeForStudent(Builder $query, int $studentId): Builder
    {
        return $query->where('student_id', $studentId);
    }

    public function scopeForTeacher(Builder $query, int $teacherId): Builder
    {
        return $query->where('teacher_id', $teacherId);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', HomeworkStatus::Pending)->where('due_at', '<', now());
    }

    public function isOverdue(): bool
    {
        return $this->status === HomeworkStatus::Pending && $this->due_at->isPast();
    }
}
