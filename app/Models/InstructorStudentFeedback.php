<?php

declare(strict_types=1);

namespace App\Models;

use App\Lessons\Enums\LessonAttendanceStatus;
use App\Lessons\Enums\LessonOutcome;
use App\Support\Concerns\PreventsHardDeletion;
use Database\Factories\InstructorStudentFeedbackFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Private, immutable-after-creation instructor-to-student lesson
 * feedback (Phase 17Q). One row per (lesson, instructor) — written
 * exclusively by SubmitInstructorStudentFeedbackAction. Never exposed
 * publicly, never contributes to a rating or aggregate, and never
 * physically deleted. `outcome_snapshot`/`source_outcome_version`
 * freeze the lesson outcome that was true at submission, so a later
 * outcome override can never silently reinterpret an existing record.
 */
class InstructorStudentFeedback extends Model
{
    /** @use HasFactory<InstructorStudentFeedbackFactory> */
    use HasFactory, HasUuids, PreventsHardDeletion;

    protected $table = 'instructor_student_feedback';

    protected $fillable = [
        'lesson_id',
        'booking_id',
        'student_id',
        'instructor_id',
        'outcome_snapshot',
        'source_outcome_version',
        'attendance_status_snapshot',
        'attendance_observation',
        'preparedness_observation',
        'homework_completion_observation',
        'engagement_observation',
        'learning_attitude_observation',
        'areas_needing_support',
        'private_notes',
        'sanitization_metadata',
        'submitted_at',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'outcome_snapshot' => LessonOutcome::class,
            'source_outcome_version' => 'integer',
            'attendance_status_snapshot' => LessonAttendanceStatus::class,
            'sanitization_metadata' => 'array',
            'submitted_at' => 'immutable_datetime',
            'version' => 'integer',
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

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function scopeForInstructor(Builder $query, int $instructorId): Builder
    {
        return $query->where('instructor_id', $instructorId);
    }

    public function scopeForLesson(Builder $query, string $lessonId): Builder
    {
        return $query->where('lesson_id', $lessonId);
    }
}
