<?php

declare(strict_types=1);

namespace App\Models;

use App\Lessons\Enums\LessonAttendanceStatus;
use App\Lessons\Enums\LessonStatus;
use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One lesson per booking (unique booking_id), created only after a
 * valid booking confirmation. Status changes go exclusively through
 * LessonLifecycleService — never mutate `status` directly.
 */
class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'booking_id',
        'student_id',
        'instructor_id',
        'subject_id',
        'subject_topic_id',
        'academic_level_id',
        'starts_at',
        'ends_at',
        'timezone',
        'status',
        'student_attended_at',
        'instructor_attended_at',
        'student_attendance_status',
        'instructor_attendance_status',
        'completed_at',
        'completed_by',
        'auto_completed_at',
        'completion_notes',
        'dispute_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => LessonStatus::class,
            'student_attendance_status' => LessonAttendanceStatus::class,
            'instructor_attendance_status' => LessonAttendanceStatus::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'student_attended_at' => 'immutable_datetime',
            'instructor_attended_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'auto_completed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
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

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function subjectTopic(): BelongsTo
    {
        return $this->belongsTo(SubjectTopic::class);
    }

    public function academicLevel(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class);
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** Lessons still awaiting an outcome: scheduled or live. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [LessonStatus::Scheduled, LessonStatus::Live]);
    }

    public function scopeForInstructor(Builder $query, int $instructorId): Builder
    {
        return $query->where('instructor_id', $instructorId);
    }

    public function scopeForStudent(Builder $query, int $studentId): Builder
    {
        return $query->where('student_id', $studentId);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'student_attendance_status', 'instructor_attendance_status', 'completed_at'])
            ->useLogName('lessons')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
