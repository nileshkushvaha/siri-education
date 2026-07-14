<?php

declare(strict_types=1);

namespace App\Models;

use App\Reviews\Enums\StudentReviewStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One frozen pre-edit snapshot of a student review (Phase 17R).
 * Append-only: written exclusively by EditStudentReviewAction, never
 * updated, never deleted. Content here is the SANITIZED text the
 * review itself carried — raw prohibited text never existed on the
 * review row, so it structurally cannot exist here. Full revision
 * content is visible only to the review owner and permissioned staff;
 * the portal shows only counts/dates.
 */
class LessonReviewRevision extends Model
{
    use HasUuids;

    protected $fillable = [
        'lesson_review_id',
        'review_version',
        'previous_overall_rating',
        'previous_teaching_quality_rating',
        'previous_communication_rating',
        'previous_punctuality_rating',
        'previous_preparedness_rating',
        'previous_learning_value_rating',
        'previous_content',
        'previous_tags',
        'previous_status',
        'previous_sanitization_metadata',
        'previous_moderation_snapshot',
        'edited_by',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'review_version' => 'integer',
            'previous_overall_rating' => 'integer',
            'previous_teaching_quality_rating' => 'integer',
            'previous_communication_rating' => 'integer',
            'previous_punctuality_rating' => 'integer',
            'previous_preparedness_rating' => 'integer',
            'previous_learning_value_rating' => 'integer',
            'previous_tags' => 'array',
            'previous_status' => StudentReviewStatus::class,
            'previous_sanitization_metadata' => 'array',
            'previous_moderation_snapshot' => 'array',
            'edited_at' => 'immutable_datetime',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(LessonReview::class, 'lesson_review_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
