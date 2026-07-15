<?php

declare(strict_types=1);

namespace App\Models;

use App\Lessons\Enums\AttendanceSource;
use App\Lessons\Enums\LessonParticipant;
use App\Support\Concerns\PreventsHardDeletion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only attendance evidence event. The unique
 * (lesson_id, fingerprint) index makes ingestion idempotent — the same
 * webhook replayed or command repeated maps to the same row and is
 * never applied twice. Rows are never updated or deleted.
 */
class LessonAttendanceEvent extends Model
{
    use HasUuids, PreventsHardDeletion;

    protected $fillable = [
        'lesson_attendance_record_id',
        'lesson_id',
        'participant',
        'source',
        'provider_reference',
        'provider_event_id',
        'fingerprint',
        'joined_at',
        'left_at',
        'attended_seconds',
        'is_late',
        'metadata',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'participant' => LessonParticipant::class,
            'source' => AttendanceSource::class,
            'joined_at' => 'immutable_datetime',
            'left_at' => 'immutable_datetime',
            'attended_seconds' => 'integer',
            'is_late' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(LessonAttendanceRecord::class, 'lesson_attendance_record_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
