<?php

declare(strict_types=1);

namespace App\Lessons\DTOs;

use App\Models\LessonAttendanceRecord;

/**
 * Outcome of an ingestion attempt. applied=true: new evidence merged
 * into the aggregates. late=true: evidence arrived after the record was
 * sealed (or the lesson left its open state) and was stored for audit
 * only — never both. Both false: idempotent duplicate, nothing written.
 */
final readonly class AttendanceRecordingResult
{
    public function __construct(
        public LessonAttendanceRecord $record,
        public bool $applied,
        public bool $late = false,
    ) {}
}
