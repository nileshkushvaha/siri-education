<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LearningPlanAssessmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningPlanAssessment extends Model
{
    protected $fillable = [
        'learning_plan_id',
        'student_user_id',
        'instructor_user_id',
        'assessment_type',
        'strengths',
        'weaknesses',
        'current_level',
        'learning_pace',
        'recommended_focus',
        'recommended_frequency',
        'notes',
        'assessed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'assessment_type' => LearningPlanAssessmentType::class,
            'assessed_at' => 'datetime',
        ];
    }

    public function learningPlan(): BelongsTo
    {
        return $this->belongsTo(StudentLearningPlan::class, 'learning_plan_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
