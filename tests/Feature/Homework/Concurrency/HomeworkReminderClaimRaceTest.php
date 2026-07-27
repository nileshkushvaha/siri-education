<?php

declare(strict_types=1);

namespace Tests\Feature\Homework\Concurrency;

use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkDueReminder;
use App\Models\User;

/**
 * Two processes race claiming the identical
 * reminder identity (same assignment, recipient, due-date snapshot,
 * offset) for the same homework assignment. The composite unique index
 * on homework_due_reminders must let exactly one process claim it; the
 * other must observe "already_claimed" — never a duplicate row, never
 * a duplicate queued send.
 */
final class HomeworkReminderClaimRaceTest extends ConcurrencyTestCase
{
    public function test_concurrent_claims_for_the_same_reminder_identity_produce_exactly_one_claim(): void
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $instructor->profile()->update(['instructor_status' => InstructorStatus::Active]);

        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => StudentStatus::Active]);

        $assignment = HomeworkAssignment::factory()->create([
            'teacher_id' => $instructor->id,
            'student_id' => $student->id,
            'status' => HomeworkStatus::Pending,
            // Real wall-clock time — child processes have no frozen
            // clock, so the window must be genuinely valid right now.
            'due_at' => now()->addHours(20),
        ]);

        $results = $this->race([
            ['claim-homework-reminder', ['assignment_id' => $assignment->id, 'offset_hours' => 24]],
            ['claim-homework-reminder', ['assignment_id' => $assignment->id, 'offset_hours' => 24]],
        ]);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($result));
        }

        $outcomes = array_map(fn (array $result): string => $result['result']['outcome'], $results);
        sort($outcomes);

        $this->assertSame(['already_claimed', 'claimed'], $outcomes);
        $this->assertSame(1, HomeworkDueReminder::query()->count());
    }
}
