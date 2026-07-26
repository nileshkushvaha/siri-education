<?php

declare(strict_types=1);

namespace App\Homework\Reminders;

use App\Enums\StudentStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Models\HomeworkAssignment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bounded, indexable candidate query for a single configured offset.
 * All comparisons are against the absolute UTC due_at instant, never a
 * student's local wall-clock time. A scheduler running late still
 * finds candidates whose threshold has passed but whose due_at has not
 * (a catch-up case); one already overdue is excluded (no post-due
 * reminder is sent for a pre-due offset).
 */
final class HomeworkReminderCandidateQuery
{
    /** @return Builder<HomeworkAssignment> */
    public function forOffset(int $offsetHours): Builder
    {
        $reminderOffsetMinutes = $offsetHours * 60;

        return HomeworkAssignment::query()
            ->where('status', HomeworkStatus::Pending)
            ->whereNotNull('due_at')
            ->where('due_at', '>', now())
            ->where('due_at', '<=', now()->addHours($offsetHours))
            ->whereHas('student.profile', function (Builder $query): void {
                $query->where('student_status', StudentStatus::Active->value);
            })
            ->whereDoesntHave('dueReminders', function (Builder $query) use ($reminderOffsetMinutes): void {
                $query->where('reminder_offset_minutes', $reminderOffsetMinutes)
                    ->whereColumn('recipient_user_id', 'homework_assignments.student_id')
                    ->whereColumn('due_at_snapshot', 'homework_assignments.due_at');
            });
    }
}
