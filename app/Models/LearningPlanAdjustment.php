<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningPlanAdjustment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'learning_plan_id',
        'changed_by',
        'change_reason',
        'previous_values',
        'new_values',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function learningPlan(): BelongsTo
    {
        return $this->belongsTo(StudentLearningPlan::class, 'learning_plan_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
