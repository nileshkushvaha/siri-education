<?php

declare(strict_types=1);

namespace App\Lessons\Contracts;

use App\Lessons\DTOs\OutcomeFinalizationResult;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Enums\LessonParticipant;
use App\Lessons\Exceptions\LessonException;
use App\Models\Lesson;
use App\Models\LessonAttendanceConfirmation;
use App\Models\LessonTechnicalIssueReport;
use App\Models\MeetingAttendanceProviderEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Administrative review of participant claims, technical-issue reports,
 * and ambiguous provider evidence. Every decision requires the
 * ReviewAttendance:Lesson permission and a reason, is row-locked
 * against concurrent reviewers, and is audited with previous/new state.
 * Accepted evidence flows through LessonAttendanceService; outcome
 * changes delegate to the Phase 17A outcome service — this service
 * never touches outcome columns or attendance aggregates itself.
 */
interface LessonReviewServiceInterface
{
    /**
     * @throws LessonException
     * @throws AuthorizationException
     */
    public function acceptConfirmation(LessonAttendanceConfirmation $confirmation, User $admin, string $reason): LessonAttendanceConfirmation;

    /**
     * @throws LessonException
     * @throws AuthorizationException
     */
    public function rejectConfirmation(LessonAttendanceConfirmation $confirmation, User $admin, string $reason): LessonAttendanceConfirmation;

    /**
     * @throws LessonException
     * @throws AuthorizationException
     */
    public function acceptTechnicalIssue(LessonTechnicalIssueReport $report, User $admin, string $reason): LessonTechnicalIssueReport;

    /**
     * Rejecting the last supporting report also releases the automation
     * hold it placed on the attendance record.
     *
     * @throws LessonException
     * @throws AuthorizationException
     */
    public function rejectTechnicalIssue(LessonTechnicalIssueReport $report, User $admin, string $reason): LessonTechnicalIssueReport;

    /**
     * Move a submission back under review with a request for more
     * evidence (audited; the row stays open).
     *
     * @throws LessonException
     * @throws AuthorizationException
     */
    public function requestAdditionalEvidence(LessonAttendanceConfirmation|LessonTechnicalIssueReport $reviewable, User $admin, string $reason): LessonAttendanceConfirmation|LessonTechnicalIssueReport;

    /**
     * Resolve a 17C review row whose participant could not be mapped:
     * the admin assigns the participant and the stored normalized
     * events are fed through the attendance service.
     *
     * @throws LessonException
     * @throws AuthorizationException
     */
    public function resolveAmbiguousEvidence(MeetingAttendanceProviderEvent $event, User $admin, LessonParticipant $participant, string $reason): MeetingAttendanceProviderEvent;

    /**
     * Finalize the lesson outcome through the existing outcome service.
     *
     * @throws LessonException
     * @throws AuthorizationException
     */
    public function finalizeOutcome(Lesson $lesson, User $admin, ?LessonOutcome $outcome = null, ?string $notes = null): OutcomeFinalizationResult;

    /**
     * Correct a finalized outcome through the existing override workflow
     * (permission + mandatory reason + override audit).
     *
     * @throws LessonException
     * @throws AuthorizationException
     */
    public function overrideOutcome(Lesson $lesson, User $admin, LessonOutcome $outcome, string $reason, ?string $notes = null): OutcomeFinalizationResult;
}
