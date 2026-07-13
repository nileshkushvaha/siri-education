<?php

declare(strict_types=1);

namespace App\Models;

use App\Lessons\Enums\LessonParticipant;
use App\Lessons\Enums\LessonReviewStatus;
use App\Lessons\Enums\TechnicalIssueCategory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * One participant-reported meeting problem. The outcome hold lives on
 * the attendance record (technical_issue_reported_at) — this row is the
 * review queue entry. Optional evidence attachments follow the existing
 * media conventions (images/PDF only — never recordings, transcripts,
 * or provider payloads). Status transitions go exclusively through
 * LessonReviewService.
 */
class LessonTechnicalIssueReport extends Model implements HasMedia
{
    use HasUuids, InteractsWithMedia;

    protected $fillable = [
        'lesson_id',
        'booking_meeting_id',
        'reporter',
        'reported_by',
        'category',
        'description',
        'occurred_at',
        'status',
        'flagged_late',
        'after_finalization',
        'reviewed_by',
        'reviewed_at',
        'review_reason',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'reporter' => LessonParticipant::class,
            'category' => TechnicalIssueCategory::class,
            'status' => LessonReviewStatus::class,
            'flagged_late' => 'boolean',
            'after_finalization' => 'boolean',
            'occurred_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('evidence')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(BookingMeeting::class, 'booking_meeting_id');
    }

    public function reporterUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
