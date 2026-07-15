<?php

declare(strict_types=1);

namespace App\Models;

use App\Reviews\Exceptions\ReviewAggregateException;
use App\Support\Concerns\PreventsHardDeletion;
use Closure;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One materialized rating summary per instructor. Sums and counts are
 * the only authoritative values — mirrors Wallet's guard: balance
 * columns may only change through the owning service
 * (InstructorRatingAggregateService), never a stray Eloquent update,
 * so the review_rating_contributions ledger and this row can never
 * silently drift apart.
 */
class InstructorRatingAggregate extends Model
{
    use HasFactory, HasUuids, PreventsHardDeletion;

    private const array GUARDED_COLUMNS = [
        'eligible_review_count', 'overall_rating_sum', 'rating_distribution',
        'paid_review_count', 'demo_review_count',
        'teaching_quality_rating_sum', 'teaching_quality_rating_count',
        'communication_rating_sum', 'communication_rating_count',
        'punctuality_rating_sum', 'punctuality_rating_count',
        'preparedness_rating_sum', 'preparedness_rating_count',
        'learning_value_rating_sum', 'learning_value_rating_count',
    ];

    private static bool $mutationAuthorized = false;

    protected static function booted(): void
    {
        static::updating(function (InstructorRatingAggregate $aggregate): void {
            if (! static::$mutationAuthorized && $aggregate->isDirty(self::GUARDED_COLUMNS)) {
                throw new ReviewAggregateException('Instructor rating aggregate columns may only be changed through InstructorRatingAggregateService.');
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
        'instructor_id',
        'eligible_review_count',
        'overall_rating_sum',
        'rating_distribution',
        'paid_review_count',
        'demo_review_count',
        'teaching_quality_rating_sum',
        'teaching_quality_rating_count',
        'communication_rating_sum',
        'communication_rating_count',
        'punctuality_rating_sum',
        'punctuality_rating_count',
        'preparedness_rating_sum',
        'preparedness_rating_count',
        'learning_value_rating_sum',
        'learning_value_rating_count',
        'last_published_review_at',
        'version',
        'rebuilt_at',
    ];

    protected function casts(): array
    {
        return [
            'eligible_review_count' => 'integer',
            'overall_rating_sum' => 'integer',
            'rating_distribution' => 'array',
            'paid_review_count' => 'integer',
            'demo_review_count' => 'integer',
            'teaching_quality_rating_sum' => 'integer',
            'teaching_quality_rating_count' => 'integer',
            'communication_rating_sum' => 'integer',
            'communication_rating_count' => 'integer',
            'punctuality_rating_sum' => 'integer',
            'punctuality_rating_count' => 'integer',
            'preparedness_rating_sum' => 'integer',
            'preparedness_rating_count' => 'integer',
            'learning_value_rating_sum' => 'integer',
            'learning_value_rating_count' => 'integer',
            'last_published_review_at' => 'immutable_datetime',
            'version' => 'integer',
            'rebuilt_at' => 'immutable_datetime',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    /** Never zero for an empty aggregate — null, per the "no average implies no reviews" rule. */
    public function overallAverage(): ?float
    {
        return $this->average($this->overall_rating_sum, $this->eligible_review_count);
    }

    public function teachingQualityAverage(): ?float
    {
        return $this->average($this->teaching_quality_rating_sum, $this->teaching_quality_rating_count);
    }

    public function communicationAverage(): ?float
    {
        return $this->average($this->communication_rating_sum, $this->communication_rating_count);
    }

    public function punctualityAverage(): ?float
    {
        return $this->average($this->punctuality_rating_sum, $this->punctuality_rating_count);
    }

    public function preparednessAverage(): ?float
    {
        return $this->average($this->preparedness_rating_sum, $this->preparedness_rating_count);
    }

    public function learningValueAverage(): ?float
    {
        return $this->average($this->learning_value_rating_sum, $this->learning_value_rating_count);
    }

    /** @return array<string, int> */
    public function distribution(): array
    {
        return $this->rating_distribution ?? [];
    }

    private function average(int $sum, int $count): ?float
    {
        return $count > 0 ? round($sum / $count, 2) : null;
    }
}
