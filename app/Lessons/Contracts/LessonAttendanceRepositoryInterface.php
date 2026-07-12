<?php

declare(strict_types=1);

namespace App\Lessons\Contracts;

use App\Models\Lesson;
use App\Models\LessonAttendanceEvent;
use App\Models\LessonAttendanceRecord;
use Illuminate\Support\Collection;

/** All Eloquent access for attendance records and their evidence events. */
interface LessonAttendanceRepositoryInterface
{
    public function findForLesson(Lesson $lesson): ?LessonAttendanceRecord;

    /**
     * The lesson's record, created on first evidence, refetched with a
     * row lock — call only inside a transaction.
     */
    public function lockOrCreateForLesson(Lesson $lesson): LessonAttendanceRecord;

    /** Refetch the record with a row lock — call only inside a transaction. */
    public function lockRecord(LessonAttendanceRecord $record): LessonAttendanceRecord;

    public function eventExists(Lesson $lesson, string $fingerprint): bool;

    /** @param array<string, mixed> $attributes */
    public function createEvent(array $attributes): LessonAttendanceEvent;

    /** @return Collection<int, LessonAttendanceEvent> */
    public function eventsForRecord(LessonAttendanceRecord $record): Collection;
}
