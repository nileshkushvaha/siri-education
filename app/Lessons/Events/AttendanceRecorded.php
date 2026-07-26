<?php

declare(strict_types=1);

namespace App\Lessons\Events;

use App\Lessons\Enums\AttendanceSource;
use App\Lessons\Enums\LessonParticipant;
use App\Models\Lesson;
use App\Models\LessonAttendanceRecord;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new piece of attendance evidence was applied (duplicates never fire
 * this). Dispatched only after the transaction commits. No earnings,
 * refund, homework, review, or notification listener is attached to
 * this event.
 */
final class AttendanceRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Lesson $lesson,
        public readonly LessonAttendanceRecord $record,
        public readonly LessonParticipant $participant,
        public readonly AttendanceSource $source,
    ) {}
}
