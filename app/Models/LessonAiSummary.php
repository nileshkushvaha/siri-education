<?php

declare(strict_types=1);

namespace App\Models;

use App\Lessons\Summaries\Enums\LessonSummaryStatus;
use App\Support\Concerns\PreventsHardDeletion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI-assisted summary for one lesson.
 *
 * `approved_summary` is the lesson's summary of record — the
 * instructor's own words after editing. `lesson_summary` and the four
 * list fields are the model's draft, retained so the two remain
 * distinguishable for as long as the row exists.
 *
 * ADVISORY UNTIL APPROVED, AND INERT AFTER. Nothing in the platform
 * reads this model to decide anything: it does not complete lessons,
 * does not touch learning-plan progress or milestones, does not set a
 * level, and is not shown to students in this release. Its lifecycle is
 * an instructor asking, editing, and approving.
 */
class LessonAiSummary extends Model
{
    use HasUuids, PreventsHardDeletion;

    protected $fillable = [
        'lesson_id',
        'ai_run_id',
        'requested_by',
        'status',
        'failure_code',
        'prompt_key',
        'prompt_version',
        'lesson_summary',
        'topics_covered',
        'strengths_observed',
        'practice_recommendations',
        'next_focus',
        'confidence',
        'requires_instructor_review',
        'approved_summary',
        'approved_by',
        'approved_at',
        'discarded_at',
        'source_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'status' => LessonSummaryStatus::class,
            'topics_covered' => 'array',
            'strengths_observed' => 'array',
            'practice_recommendations' => 'array',
            'next_focus' => 'array',
            'source_snapshot' => 'array',
            'confidence' => 'float',
            'requires_instructor_review' => 'boolean',
            'approved_at' => 'immutable_datetime',
            'discarded_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
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

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function confidencePercent(): ?int
    {
        return $this->confidence === null ? null : (int) round($this->confidence * 100);
    }
}
