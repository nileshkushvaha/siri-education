<?php

declare(strict_types=1);

namespace App\Models;

use App\Ai\Enums\AiFailureCode;
use App\Ai\Enums\AiFeature;
use App\Ai\Enums\AiRunStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One AI execution's operational metadata. The canonical answer to
 * "what did the AI layer do, when, for which feature, at what cost".
 *
 * Holds NO prompt and NO response — see the migration for why. Anything
 * that wants to show an admin what the model said must get it from the
 * feature that persisted the validated result in its own domain, never
 * from here.
 *
 * No LogsActivity trait: this is machine telemetry written on every
 * call, and mirroring it into activity_log would bury the business
 * audit trail. AI settings changes are audited separately through the
 * settings page's AuditTrailService path.
 */
class AiRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'feature_key',
        'provider',
        'model',
        'prompt_key',
        'prompt_version',
        'subject_type',
        'subject_id',
        'requested_by',
        'status',
        'failure_code',
        'input_tokens',
        'output_tokens',
        'estimated_cost',
        'cost_currency',
        'latency_ms',
        'provider_request_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'feature_key' => AiFeature::class,
            'status' => AiRunStatus::class,
            'failure_code' => AiFailureCode::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'estimated_cost' => 'decimal:6',
            'latency_ms' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * The record this run was about. Unconstrained and nullable — a run
     * outlives its subject, and telemetry must never keep a deleted
     * record alive.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function totalTokens(): int
    {
        return $this->input_tokens + $this->output_tokens;
    }
}
