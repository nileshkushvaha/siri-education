<?php

declare(strict_types=1);

namespace App\Lessons\Contracts;

use App\Lessons\DTOs\AttendanceEvidenceData;
use App\Lessons\DTOs\AttendanceRecordingResult;
use App\Lessons\Exceptions\LessonAttendanceException;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Single entry point for attendance evidence ingestion and
 * finalization. Provider webhooks/sync jobs call record() with a null
 * or system actor; manual sources (instructor confirmation, admin)
 * must pass the acting user and are policy-checked against the lesson.
 */
interface LessonAttendanceServiceInterface
{
    /**
     * Idempotent: replayed provider events and repeated commands return
     * applied=false and write nothing.
     *
     * @throws LessonAttendanceException
     * @throws AuthorizationException
     */
    public function record(Lesson $lesson, AttendanceEvidenceData $evidence, ?User $actor = null): AttendanceRecordingResult;

    /**
     * Seal the attendance record and bridge qualifying evidence into
     * the lesson's per-party attendance statuses. Idempotent.
     *
     * @throws LessonAttendanceException
     * @throws AuthorizationException
     */
    public function finalize(Lesson $lesson, ?User $actor = null): AttendanceRecordingResult;
}
