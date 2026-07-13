<?php

declare(strict_types=1);

namespace App\Lessons\Contracts;

use App\Lessons\DTOs\AttendanceConfirmationData;
use App\Lessons\DTOs\TechnicalIssueReportData;
use App\Lessons\Exceptions\LessonException;
use App\Models\Lesson;
use App\Models\LessonAttendanceConfirmation;
use App\Models\LessonTechnicalIssueReport;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Participant-facing fallback when provider attendance is unavailable:
 * attendance claims and technical-issue reports. The participant role
 * is always derived from the authenticated user against the lesson's
 * stored participants — never from the request. Claims are evidence
 * candidates for admin review, not outcome mutations.
 */
interface LessonConfirmationServiceInterface
{
    /**
     * Idempotent per identical claim; a different claim creates a new
     * row so history is preserved.
     *
     * @throws LessonException
     * @throws AuthorizationException
     */
    public function submitConfirmation(Lesson $lesson, User $user, AttendanceConfirmationData $data): LessonAttendanceConfirmation;

    /**
     * In-window reports also place the Phase 17B outcome hold (via the
     * attendance record's technical-issue flag); late or
     * post-finalization reports are stored flagged for review only.
     *
     * @throws LessonException
     * @throws AuthorizationException
     */
    public function reportTechnicalIssue(Lesson $lesson, User $user, TechnicalIssueReportData $data): LessonTechnicalIssueReport;
}
