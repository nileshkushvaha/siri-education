<?php

declare(strict_types=1);

namespace App\Lessons\Repositories;

use App\Lessons\Contracts\LessonAttendanceRepositoryInterface;
use App\Models\Lesson;
use App\Models\LessonAttendanceEvent;
use App\Models\LessonAttendanceRecord;
use Illuminate\Support\Collection;

final class LessonAttendanceRepository implements LessonAttendanceRepositoryInterface
{
    public function findForLesson(Lesson $lesson): ?LessonAttendanceRecord
    {
        return LessonAttendanceRecord::query()->where('lesson_id', $lesson->id)->first();
    }

    public function lockOrCreateForLesson(Lesson $lesson): LessonAttendanceRecord
    {
        // firstOrCreate before the locked refetch: the unique lesson_id
        // index makes a concurrent double-create impossible, and the
        // lockForUpdate read is the authoritative copy either way.
        LessonAttendanceRecord::query()->firstOrCreate(
            ['lesson_id' => $lesson->id],
            [
                'booking_id' => $lesson->booking_id,
                'booking_meeting_id' => $lesson->booking?->meeting?->id,
            ],
        );

        return LessonAttendanceRecord::query()
            ->where('lesson_id', $lesson->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function lockRecord(LessonAttendanceRecord $record): LessonAttendanceRecord
    {
        return LessonAttendanceRecord::query()
            ->whereKey($record->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function eventExists(Lesson $lesson, string $fingerprint): bool
    {
        return LessonAttendanceEvent::query()
            ->where('lesson_id', $lesson->id)
            ->where('fingerprint', $fingerprint)
            ->exists();
    }

    public function createEvent(array $attributes): LessonAttendanceEvent
    {
        return LessonAttendanceEvent::query()->create($attributes);
    }

    public function eventsForRecord(LessonAttendanceRecord $record): Collection
    {
        return LessonAttendanceEvent::query()
            ->where('lesson_attendance_record_id', $record->id)
            ->orderBy('joined_at')
            ->get();
    }
}
