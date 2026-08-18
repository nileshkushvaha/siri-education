<?php

declare(strict_types=1);

namespace App\Models;

use App\Homework\Copilot\Enums\HomeworkFeedbackDraftStatus;
use App\Support\Concerns\PreventsHardDeletion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI-drafted feedback suggestion for one homework submission.
 *
 * A SUGGESTION, never an outcome. Nothing in the platform reads this
 * model to decide anything: it does not grade, does not change homework
 * status, does not touch the learning plan, does not notify the student,
 * and is never shown to the student at any point. Its entire lifecycle
 * is: an instructor asks, a draft appears, the instructor uses or
 * discards it.
 *
 * The published feedback lives on HomeworkAssignment::$feedback and is
 * written only from what the instructor typed. These two are never the
 * same text by construction — see the migration.
 */
class HomeworkAiFeedbackDraft extends Model
{
    use HasUuids, PreventsHardDeletion;

    protected $fillable = [
        'homework_assignment_id',
        'ai_run_id',
        'requested_by',
        'status',
        'failure_code',
        'prompt_key',
        'prompt_version',
        'summary',
        'strengths',
        'improvements',
        'suggested_feedback',
        'confidence',
        'requires_instructor_review',
        'source_snapshot',
        'used_at',
        'discarded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => HomeworkFeedbackDraftStatus::class,
            'strengths' => 'array',
            'improvements' => 'array',
            'source_snapshot' => 'array',
            'confidence' => 'float',
            'requires_instructor_review' => 'boolean',
            'used_at' => 'immutable_datetime',
            'discarded_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<HomeworkAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(HomeworkAssignment::class, 'homework_assignment_id');
    }

    /** @return BelongsTo<AiRun, $this> */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function confidencePercent(): ?int
    {
        return $this->confidence === null ? null : (int) round($this->confidence * 100);
    }
}
