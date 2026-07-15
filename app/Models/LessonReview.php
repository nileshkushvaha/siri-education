<?php

declare(strict_types=1);

namespace App\Models;

use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Enums\StudentReviewStatus;
use App\Support\Concerns\PreventsHardDeletion;
use Database\Factories\LessonReviewFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One student review per eligibility (and per booking). Created
 * exclusively by SubmitLessonReviewAction; status transitions
 * exclusively by ModerateSubmittedReviewAction (automatic) and
 * ReviewModerationService (admin) — never physically deleted, and the
 * rating/text/tags a student submitted are never edited by moderation.
 * `content` is always the sanitized plain-text form; raw submitted
 * text never reaches this table. `Published` is the only status a
 * future public-display/aggregate phase may ever surface.
 */
class LessonReview extends Model
{
    /** @use HasFactory<LessonReviewFactory> */
    use HasFactory, HasUuids, LogsActivity, PreventsHardDeletion;

    protected $fillable = [
        'eligibility_id',
        'lesson_id',
        'booking_id',
        'student_id',
        'instructor_id',
        'review_mode',
        'overall_rating',
        'teaching_quality_rating',
        'communication_rating',
        'punctuality_rating',
        'preparedness_rating',
        'learning_value_rating',
        'content',
        'tags',
        'status',
        'submitted_at',
        'settings_snapshot',
        'sanitization_metadata',
        'version',
        'moderated_at',
        'moderated_by',
        'moderation_reason',
        'moderation_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'review_mode' => LessonReviewEligibilityMode::class,
            'overall_rating' => 'integer',
            'teaching_quality_rating' => 'integer',
            'communication_rating' => 'integer',
            'punctuality_rating' => 'integer',
            'preparedness_rating' => 'integer',
            'learning_value_rating' => 'integer',
            'tags' => 'array',
            'status' => StudentReviewStatus::class,
            'submitted_at' => 'immutable_datetime',
            'settings_snapshot' => 'array',
            'sanitization_metadata' => 'array',
            'version' => 'integer',
            'moderated_at' => 'immutable_datetime',
            'moderation_snapshot' => 'array',
        ];
    }

    public function eligibility(): BelongsTo
    {
        return $this->belongsTo(LessonReviewEligibility::class, 'eligibility_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** withTrashed() — an archived (soft-deleted) booking must still resolve here; see PreventsHardDeletion / Phase 17U.1. */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ReviewReport::class, 'review_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(LessonReviewRevision::class, 'lesson_review_id');
    }

    public function isPrivate(): bool
    {
        return $this->review_mode === LessonReviewEligibilityMode::PrivateFeedback;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'review_mode'])
            ->useLogName('reviews')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
