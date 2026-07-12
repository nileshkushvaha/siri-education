<?php

declare(strict_types=1);

namespace App\Lessons\DTOs;

use App\Models\LessonAttendanceRecord;

/** Outcome of an ingestion attempt — applied=false means idempotent duplicate, not failure. */
final readonly class AttendanceRecordingResult
{
    public function __construct(
        public LessonAttendanceRecord $record,
        public bool $applied,
    ) {}
}
