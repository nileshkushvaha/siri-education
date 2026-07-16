<?php

declare(strict_types=1);

namespace App\Models;

use App\Reviews\Exceptions\ReviewAggregateException;
use App\Support\Concerns\PreventsHardDeletion;
use Closure;
use Database\Factories\ReviewRatingContributionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exactly one row per LessonReview (unique review_id) — whether that
 * review currently contributes to its instructor's rating aggregate.
 * Written exclusively by ReconcileReviewContributionAction /
 * RebuildInstructorRatingAggregateAction. `included` toggling is what
 * makes every publish/hide/restore/reject/archive event idempotent:
 * an event that doesn't change the desired state is a no-op. Mirrors
 * InstructorRatingAggregate's guard: these columns may only change
 * through one of those two actions, never a stray Eloquent update, so
 * the ledger and the aggregate it feeds can never silently drift apart.
 */
class ReviewRatingContribution extends Model
{
    /** @use HasFactory<ReviewRatingContributionFactory> */
    use HasFactory, HasUuids, PreventsHardDeletion;

    private const array GUARDED_COLUMNS = [
        'included', 'overall_rating', 'teaching_quality_rating', 'communication_rating',
        'punctuality_rating', 'preparedness_rating', 'learning_value_rating',
        'lesson_type', 'applied_review_version', 'applied_at', 'removed_at',
    ];

    private static bool $mutationAuthorized = false;

    protected static function booted(): void
    {
        static::updating(function (ReviewRatingContribution $contribution): void {
            if (! static::$mutationAuthorized && $contribution->isDirty(self::GUARDED_COLUMNS)) {
                throw new ReviewAggregateException('Review rating contribution columns may only be changed through ReconcileReviewContributionAction or RebuildInstructorRatingAggregateAction.');
            }
        });
    }

    /** @param  Closure(): mixed  $callback */
    public static function withAuthorizedMutation(Closure $callback): mixed
    {
        static::$mutationAuthorized = true;

        try {
            return $callback();
        } finally {
            static::$mutationAuthorized = false;
        }
    }

    protected $fillable = [
        'review_id',
        'instructor_id',
        'included',
        'overall_rating',
        'teaching_quality_rating',
        'communication_rating',
        'punctuality_rating',
        'preparedness_rating',
        'learning_value_rating',
        'lesson_type',
        'applied_review_version',
        'applied_at',
        'removed_at',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'included' => 'boolean',
            'overall_rating' => 'integer',
            'teaching_quality_rating' => 'integer',
            'communication_rating' => 'integer',
            'punctuality_rating' => 'integer',
            'preparedness_rating' => 'integer',
            'learning_value_rating' => 'integer',
            'applied_review_version' => 'integer',
            'applied_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(LessonReview::class, 'review_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }
}
