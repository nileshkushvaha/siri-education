<?php

declare(strict_types=1);

namespace App\Models;

use App\Lessons\Enums\AttendanceSource;
use App\Lessons\Enums\LessonParticipant;
use App\Support\Concerns\PreventsHardDeletion;
use Database\Factories\LessonAttendanceRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The single authoritative attendance record for one lesson (unique
 * booking occurrence). Aggregates are merged from the append-only
 * lesson_attendance_events log — never mutate them directly; all
 * writes go through RecordAttendanceAction / FinalizeAttendanceAction.
 * Metadata is a sanitized snapshot; raw provider payloads never land here.
 */
class LessonAttendanceRecord extends Model
{
    /** @use HasFactory<LessonAttendanceRecordFactory> */
    use HasFactory, HasUuids, PreventsHardDeletion;

    protected $fillable = [
        'lesson_id',
        'booking_id',
        'booking_meeting_id',
        'student_first_joined_at',
        'student_last_left_at',
        'student_attended_seconds',
        'student_join_count',
        'instructor_first_joined_at',
        'instructor_last_left_at',
        'instructor_attended_seconds',
        'instructor_join_count',
        'source',
        'provider_reference',
        'metadata',
        'technical_issue_reported_at',
        'late_evidence_reported_at',
        'finalized_at',
        'finalized_by',
    ];

    protected function casts(): array
    {
        return [
            'source' => AttendanceSource::class,
            'student_first_joined_at' => 'immutable_datetime',
            'student_last_left_at' => 'immutable_datetime',
            'student_attended_seconds' => 'integer',
            'student_join_count' => 'integer',
            'instructor_first_joined_at' => 'immutable_datetime',
            'instructor_last_left_at' => 'immutable_datetime',
            'instructor_attended_seconds' => 'integer',
            'instructor_join_count' => 'integer',
            'metadata' => 'array',
            'technical_issue_reported_at' => 'immutable_datetime',
            'late_evidence_reported_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
        ];
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

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(BookingMeeting::class, 'booking_meeting_id');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LessonAttendanceEvent::class);
    }

    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }

    /**
     * Qualifying attendance = at least one recorded join and a merged
     * duration meeting the platform minimum (0 = any join qualifies).
     */
    public function hasQualifyingAttendance(LessonParticipant $participant, int $minAttendanceSeconds): bool
    {
        return $this->joinCountFor($participant) > 0
            && $this->attendedSecondsFor($participant) >= max(0, $minAttendanceSeconds);
    }

    public function joinCountFor(LessonParticipant $participant): int
    {
        return (int) $this->getAttribute("{$participant->value}_join_count");
    }

    public function attendedSecondsFor(LessonParticipant $participant): int
    {
        return (int) $this->getAttribute("{$participant->value}_attended_seconds");
    }
}
