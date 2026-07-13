<?php

declare(strict_types=1);

namespace App\Models;

use App\Lessons\Enums\LessonParticipant;
use App\Lessons\Enums\LessonReviewStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One participant attendance claim, append-only. Status transitions go
 * exclusively through LessonReviewService (row-locked, audited);
 * accepted claims feed LessonAttendanceService — never aggregates or
 * outcomes directly.
 */
class LessonAttendanceConfirmation extends Model
{
    use HasUuids;

    protected $fillable = [
        'lesson_id',
        'participant',
        'submitted_by',
        'claimed_joined_at',
        'claimed_left_at',
        'claimed_attended_minutes',
        'notes',
        'fingerprint',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_reason',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'participant' => LessonParticipant::class,
            'status' => LessonReviewStatus::class,
            'claimed_joined_at' => 'immutable_datetime',
            'claimed_left_at' => 'immutable_datetime',
            'claimed_attended_minutes' => 'integer',
            'reviewed_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
