<?php

declare(strict_types=1);

namespace App\Models;

use App\Quality\Intelligence\Enums\QualityInsightStatus;
use App\Support\Concerns\PreventsHardDeletion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI-assisted quality briefing about one instructor, for one
 * reporting period.
 *
 * ADVISORY BY CONSTRUCTION. Nothing in the platform reads this model to
 * decide anything: it has no score, no rank, no severity, and no
 * relationship to quality alerts, instructor status, compensation, or
 * payouts. It is written by QualityInsightService and read by an
 * administrator — that is the whole lifecycle.
 *
 * Deliberately NOT an InstructorQualityAlert. An alert is a
 * deterministic, rule-derived signal with a resolution workflow that
 * feeds real operational process; this is a model's non-deterministic
 * reading of existing signals. Merging them would let a probabilistic
 * opinion enter a pipeline built for facts.
 */
class AiQualityInsight extends Model
{
    use HasUuids, PreventsHardDeletion;

    protected $fillable = [
        'instructor_id',
        'ai_run_id',
        'period_preset',
        'period_start',
        'period_end',
        'period_timezone',
        'period_label',
        'status',
        'failure_code',
        'prompt_key',
        'prompt_version',
        'summary',
        'strengths',
        'concerns',
        'recommended_review',
        'confidence',
        'requires_human_review',
        'source_snapshot',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => QualityInsightStatus::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'strengths' => 'array',
            'concerns' => 'array',
            'source_snapshot' => 'array',
            'confidence' => 'float',
            'requires_human_review' => 'boolean',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
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
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Confidence as a whole-number percentage for display; null when the run never produced one. */
    public function confidencePercent(): ?int
    {
        return $this->confidence === null ? null : (int) round($this->confidence * 100);
    }
}
