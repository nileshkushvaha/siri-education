<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningPlanReview extends Model
{
    protected $fillable = [
        'learning_plan_id',
        'student_user_id',
        'instructor_user_id',
        'review_number',
        'summary',
        'progress_notes',
        'challenges',
        'recommendations',
        'homework_quality_note',
        'attendance_note',
        'next_focus',
        'reviewed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'review_number' => 'integer',
            'reviewed_at' => 'datetime',
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
