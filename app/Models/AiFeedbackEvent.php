<?php

declare(strict_types=1);

namespace App\Models;

use App\Ai\Enums\AiFeature;
use App\Ai\Evaluation\Enums\AiFeedbackAction;
use App\Ai\Evaluation\Enums\AiFeedbackReason;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reviewer's verdict on one AI output.
 *
 * Owned by the AI module in the same sense AiRun is: it is telemetry
 * about the AI system, not a business record about a person. It carries
 * no subject, no content and no free text — see the migration.
 */
class AiFeedbackEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'ai_run_id',
        'feature_key',
        'prompt_key',
        'prompt_version',
        'actor_id',
        'action',
        'reason_code',
    ];

    protected function casts(): array
    {
        return [
            'feature_key' => AiFeature::class,
            'action' => AiFeedbackAction::class,
            'reason_code' => AiFeedbackReason::class,
        ];
    }

    /** @return BelongsTo<AiRun, $this> */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
