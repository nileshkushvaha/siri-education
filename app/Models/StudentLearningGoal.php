<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LearningGoalStatus;
use App\Enums\LearningGoalType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudentLearningGoal extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'subject_id',
        'academic_level_id',
        'title',
        'type',
        'description',
        'target_date',
        'priority',
        'status',
        'completed_at',
        'archived_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => LearningGoalType::class,
            'status' => LearningGoalStatus::class,
            'target_date' => 'date',
            'priority' => 'integer',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function academicLevel(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function learningPlans(): HasMany
    {
        return $this->hasMany(StudentLearningPlan::class, 'learning_goal_id');
    }

    public function scopeActiveForDashboard(Builder $query): Builder
    {
        return $query->whereIn('status', [
            LearningGoalStatus::Draft->value,
            LearningGoalStatus::Active->value,
            LearningGoalStatus::Paused->value,
        ]);
    }

    public function scopeHistorical(Builder $query): Builder
    {
        return $query->whereIn('status', [
            LearningGoalStatus::Completed->value,
            LearningGoalStatus::Archived->value,
        ]);
    }
}
