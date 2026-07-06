<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LearningPlanMilestoneStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LearningPlanMilestone extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'learning_plan_id',
        'title',
        'description',
        'target_date',
        'status',
        'completed_at',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => LearningPlanMilestoneStatus::class,
            'target_date' => 'date',
            'completed_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function learningPlan(): BelongsTo
    {
        return $this->belongsTo(StudentLearningPlan::class, 'learning_plan_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
