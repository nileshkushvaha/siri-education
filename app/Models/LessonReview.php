<?php

declare(strict_types=1);

namespace App\Models;

use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Enums\StudentReviewStatus;
use Database\Factories\LessonReviewFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One student review per eligibility (and per booking). Written
 * exclusively by SubmitLessonReviewAction — never physically deleted.
 * `content` is always the sanitized plain-text form; raw submitted
 * text never reaches this table. Phase 17I never publishes, aggregates,
 * or exposes any review — `status` staying Submitted/Private/Flagged
 * is itself the "invisible until moderation" guarantee.
 */
class LessonReview extends Model
{
    /** @use HasFactory<LessonReviewFactory> */
    use HasFactory, HasUuids, LogsActivity;

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

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
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
