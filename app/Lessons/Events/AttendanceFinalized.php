<?php

declare(strict_types=1);

namespace App\Lessons\Events;

use App\Models\Lesson;
use App\Models\LessonAttendanceRecord;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The attendance record was sealed — no further evidence is accepted.
 * Dispatched only after the transaction commits, at most once per record.
 */
final class AttendanceFinalized implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Lesson $lesson,
        public readonly LessonAttendanceRecord $record,
    ) {}
}
