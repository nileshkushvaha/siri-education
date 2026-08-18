<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Messaging\Safety\Enums\MessageSafetyCategory;
use App\Messaging\Safety\Enums\MessageSafetyFindingStatus;
use App\Messaging\Safety\Enums\MessageSafetyRiskLevel;
use App\Messaging\Safety\Enums\MessageSafetySource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One safety finding about one message.
 *
 * EVIDENCE, NEVER ENFORCEMENT. Nothing in the platform reads this model
 * to restrict a user, hide a message, or change an account: it has no
 * enforcement column, and the only automated consumer is
 * RepeatedCommunicationRiskFindingsRule, which counts CONFIRMED
 * findings — a human decision — to raise a normal compliance flag for
 * human review.
 *
 * Holds no message text. `reason` is a short description of the
 * message produced by the model; the message itself stays in `messages`.
 *
 * `source_type` distinguishes a verifiable pattern match from a model's
 * opinion. Always show it: an admin acting on "contains an email
 * address" and on "may be proposing to move off-platform" is making two
 * very different judgements.
 */
class MessageSafetyFinding extends Model
{
    use HasUuids;

    /**
     * The one AI record in the platform that is deliberately DELETABLE,
     * and only in one direction.
     *
     * Every other AI record (insights, drafts, summaries) is historical
     * business evidence and uses PreventsHardDeletion. A safety finding
     * is different: it is a suspicion about a person, raised without
     * anyone asking, and when the analysis concludes there was nothing
     * to see, the right outcome is that no record of the suspicion
     * survives. Leaving a row behind would mean an innocent message
     * permanently carries a safety file because a phrase gate once
     * looked at it.
     *
     * A finding an administrator has already REVIEWED is the opposite —
     * that is a human decision and part of the compliance record — so
     * this guard makes terminal findings undeletable, mirroring
     * PreventsHardDeletion for exactly the rows that deserve it.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $finding): void {
            if ($finding->status->isTerminal()) {
                throw new HistoricalRecordCannotBeDeletedException(sprintf(
                    'MessageSafetyFinding #%s has been reviewed and cannot be deleted.',
                    $finding->getKey(),
                ));
            }
        });
    }

    protected $fillable = [
        'message_id',
        'sender_id',
        'source_type',
        'category',
        'risk_level',
        'reason',
        'confidence',
        'detected_patterns',
        'ai_run_id',
        'prompt_key',
        'prompt_version',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => MessageSafetySource::class,
            'category' => MessageSafetyCategory::class,
            'risk_level' => MessageSafetyRiskLevel::class,
            'status' => MessageSafetyFindingStatus::class,
            'detected_patterns' => 'array',
            'confidence' => 'float',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** @return BelongsTo<AiRun, $this> */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @param Builder<$this> $query */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', MessageSafetyFindingStatus::Confirmed);
    }

    public function confidencePercent(): ?int
    {
        return $this->confidence === null ? null : (int) round($this->confidence * 100);
    }
}
