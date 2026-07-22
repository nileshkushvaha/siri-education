<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LearningPlanStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudentLearningPlan extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'student_user_id',
        'learning_goal_id',
        'primary_instructor_user_id',
        'subject_id',
        'academic_level_id',
        'title',
        'summary',
        'initial_assessment',
        'recommended_frequency',
        'recommended_lesson_duration_minutes',
        'preferred_schedule_note',
        'current_focus',
        'current_level_note',
        'target_completion_date',
        'status',
        'progress_percent',
        'started_at',
        'completed_at',
        'archived_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => LearningPlanStatus::class,
            'progress_percent' => 'integer',
            'recommended_lesson_duration_minutes' => 'integer',
            'target_completion_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    public function learningGoal(): BelongsTo
    {
        return $this->belongsTo(StudentLearningGoal::class, 'learning_goal_id');
    }

    public function primaryInstructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_instructor_user_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function academicLevel(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(LearningPlanAssessment::class, 'learning_plan_id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(LearningPlanMilestone::class, 'learning_plan_id')->orderBy('sort_order')->orderBy('id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(LearningPlanReview::class, 'learning_plan_id')->orderBy('review_number');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(LearningPlanAdjustment::class, 'learning_plan_id')->latest('created_at');
    }

    public function homeworkAssignments(): HasMany
    {
        return $this->hasMany(HomeworkAssignment::class, 'learning_plan_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActiveForDashboard(Builder $query): Builder
    {
        return $query->whereIn('status', [
            LearningPlanStatus::Active->value,
            LearningPlanStatus::Paused->value,
            LearningPlanStatus::ReviewDue->value,
            LearningPlanStatus::AwaitingAssessment->value,
        ]);
    }

    public function scopeHistorical(Builder $query): Builder
    {
        return $query->whereIn('status', [
            LearningPlanStatus::Completed->value,
            LearningPlanStatus::Archived->value,
        ]);
    }
}
