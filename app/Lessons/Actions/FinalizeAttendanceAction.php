<?php

declare(strict_types=1);

namespace App\Lessons\Actions;

use App\Booking\Enums\BookingStatus;
use App\Lessons\Contracts\LessonAttendanceRepositoryInterface;
use App\Lessons\DTOs\AttendanceRecordingResult;
use App\Lessons\Enums\LessonAttendanceStatus;
use App\Lessons\Enums\LessonParticipant;
use App\Lessons\Exceptions\LessonAttendanceException;
use App\Models\Lesson;
use App\Models\User;
use App\Settings\LessonSettings;
use Illuminate\Support\Facades\DB;

/**
 * Seals the lesson's attendance record: no further evidence is
 * accepted afterwards. Bridges evidence into the existing per-party
 * lesson attendance statuses — but only fills statuses still Pending;
 * an explicit human mark (instructor confirmation, admin) always wins
 * over provider evidence. Idempotent: re-finalizing returns
 * applied=false. A cancelled booking always rejects finalization.
 */
final class FinalizeAttendanceAction
{
    public function __construct(
        private readonly LessonAttendanceRepositoryInterface $attendance,
        private readonly LessonSettings $settings,
    ) {}

    /** @throws LessonAttendanceException */
    public function execute(Lesson $lesson, ?User $actor = null): AttendanceRecordingResult
    {
        $booking = $lesson->booking;

        if ($booking === null || $booking->status === BookingStatus::Cancelled) {
            throw new LessonAttendanceException('Attendance cannot be finalized for a cancelled booking.');
        }

        $record = $this->attendance->findForLesson($lesson);

        if ($record === null) {
            throw new LessonAttendanceException('No attendance has been recorded for this lesson.');
        }

        return DB::transaction(function () use ($lesson, $record, $actor): AttendanceRecordingResult {
            $record = $this->attendance->lockRecord($record);

            if ($record->isFinalized()) {
                return new AttendanceRecordingResult($record, applied: false);
            }

            $record->fill([
                'finalized_at' => now()->toImmutable()->utc(),
                'finalized_by' => $actor?->id,
            ])->save();

            if ($lesson->status->isOpen()) {
                $statuses = [];

                foreach (LessonParticipant::cases() as $participant) {
                    if ($lesson->getAttribute("{$participant->value}_attendance_status") !== LessonAttendanceStatus::Pending) {
                        continue;
                    }

                    $qualifies = $record->hasQualifyingAttendance($participant, $this->settings->min_attendance_seconds);
                    $statuses["{$participant->value}_attendance_status"] = $qualifies
                        ? LessonAttendanceStatus::Attended
                        : LessonAttendanceStatus::NoShow;
                    $statuses["{$participant->value}_attended_at"] = $qualifies
                        ? $record->getAttribute("{$participant->value}_first_joined_at")
                        : null;
                }

                if ($statuses !== []) {
                    $lesson->fill($statuses)->save();
                }
            }

            return new AttendanceRecordingResult($record, applied: true);
        });
    }
}
