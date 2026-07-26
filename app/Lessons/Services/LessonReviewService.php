<?php

declare(strict_types=1);

namespace App\Lessons\Services;

use App\Booking\DTOs\ProviderAttendanceEvent;
use App\Booking\Enums\MeetingAttendanceProcessingStatus;
use App\Lessons\Contracts\LessonAttendanceServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Contracts\LessonReviewServiceInterface;
use App\Lessons\DTOs\AttendanceEvidenceData;
use App\Lessons\DTOs\OutcomeFinalizationResult;
use App\Lessons\Enums\AttendanceSource;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Enums\LessonParticipant;
use App\Lessons\Enums\LessonReviewStatus;
use App\Lessons\Exceptions\LessonException;
use App\Models\Lesson;
use App\Models\LessonAttendanceConfirmation;
use App\Models\LessonTechnicalIssueReport;
use App\Models\MeetingAttendanceProviderEvent;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Administrative review desk. Decisions are row-locked
 * (two admins can never resolve the same submission differently — the
 * second sees a settled row and gets a conflict; repeating the same
 * decision is an idempotent no-op), reasoned, and audited with the
 * previous and new state. Accepted attendance flows through
 * LessonAttendanceService (so idempotency/late/sealed semantics hold);
 * outcome changes delegate to LessonOutcomeService.
 */
final class LessonReviewService implements LessonReviewServiceInterface
{
    private const string LOG_NAME = 'lessons';

    public function __construct(
        private readonly LessonAttendanceServiceInterface $attendance,
        private readonly LessonOutcomeServiceInterface $outcomes,
        private readonly AuditTrailService $audit,
    ) {}

    public function acceptConfirmation(LessonAttendanceConfirmation $confirmation, User $admin, string $reason): LessonAttendanceConfirmation
    {
        $lesson = $confirmation->lesson;
        $this->authorizeReview($admin, $lesson);
        $this->assertReason($reason);

        return DB::transaction(function () use ($confirmation, $admin, $reason, $lesson): LessonAttendanceConfirmation {
            $confirmation = $this->lockConfirmation($confirmation);
            $previous = $confirmation->status;

            if ($previous === LessonReviewStatus::Accepted) {
                return $confirmation; // idempotent repeat of the same decision
            }

            $this->assertOpen($previous);

            $result = $this->attendance->record($lesson, new AttendanceEvidenceData(
                participant: $confirmation->participant,
                source: $confirmation->participant === LessonParticipant::Instructor
                    ? AttendanceSource::InstructorConfirmation
                    : AttendanceSource::StudentConfirmation,
                joinedAt: $confirmation->claimed_joined_at,
                leftAt: $confirmation->claimed_left_at,
                attendedSeconds: $confirmation->claimed_left_at === null && $confirmation->claimed_attended_minutes !== null
                    ? $confirmation->claimed_attended_minutes * 60
                    : null,
                providerEventId: 'confirmation:'.$confirmation->id,
                metadata: ['confirmation_id' => $confirmation->id],
            ), $admin);

            $this->settleReview($confirmation, LessonReviewStatus::Accepted, $admin, $reason);

            $this->audit->logUser($admin, self::LOG_NAME, 'lesson_attendance_confirmation_accepted',
                sprintf('Lesson %s: %s attendance confirmation accepted.', $lesson->id, $confirmation->participant->label()),
                $lesson,
                [
                    'confirmation_id' => $confirmation->id,
                    'previous_status' => $previous->value,
                    'new_status' => LessonReviewStatus::Accepted->value,
                    'reason' => $reason,
                    'evidence_applied' => $result->applied,
                    'evidence_late' => $result->late,
                    'booking_id' => $lesson->booking_id,
                ],
            );

            return $confirmation;
        });
    }

    public function rejectConfirmation(LessonAttendanceConfirmation $confirmation, User $admin, string $reason): LessonAttendanceConfirmation
    {
        $lesson = $confirmation->lesson;
        $this->authorizeReview($admin, $lesson);
        $this->assertReason($reason);

        return DB::transaction(function () use ($confirmation, $admin, $reason, $lesson): LessonAttendanceConfirmation {
            $confirmation = $this->lockConfirmation($confirmation);
            $previous = $confirmation->status;

            if ($previous === LessonReviewStatus::Rejected) {
                return $confirmation;
            }

            $this->assertOpen($previous);
            $this->settleReview($confirmation, LessonReviewStatus::Rejected, $admin, $reason);

            $this->audit->logUser($admin, self::LOG_NAME, 'lesson_attendance_confirmation_rejected',
                sprintf('Lesson %s: %s attendance confirmation rejected.', $lesson->id, $confirmation->participant->label()),
                $lesson,
                [
                    'confirmation_id' => $confirmation->id,
                    'previous_status' => $previous->value,
                    'new_status' => LessonReviewStatus::Rejected->value,
                    'reason' => $reason,
                ],
            );

            return $confirmation;
        });
    }

    public function acceptTechnicalIssue(LessonTechnicalIssueReport $report, User $admin, string $reason): LessonTechnicalIssueReport
    {
        $lesson = $report->lesson;
        $this->authorizeReview($admin, $lesson);
        $this->assertReason($reason);

        return DB::transaction(function () use ($report, $admin, $reason, $lesson): LessonTechnicalIssueReport {
            $report = $this->lockReport($report);
            $previous = $report->status;

            if ($previous === LessonReviewStatus::Accepted) {
                return $report;
            }

            $this->assertOpen($previous);

            // Late/post-finalization reports never placed the hold — an
            // acceptance places it now when the lesson is still open;
            // finalized lessons keep their outcome (correcting it requires
            // the explicit override workflow).
            if (! $lesson->hasFinalizedOutcome()
                && $lesson->attendanceRecord?->technical_issue_reported_at === null) {
                $this->attendance->record($lesson, new AttendanceEvidenceData(
                    participant: $report->reporter,
                    source: AttendanceSource::AdminOverride,
                    providerEventId: 'tech-issue-accepted:'.$report->id,
                    metadata: ['technical_issue_category' => $report->category->value],
                    technicalIssueReported: true,
                ), $admin);
            }

            $this->settleReview($report, LessonReviewStatus::Accepted, $admin, $reason);

            $this->audit->logUser($admin, self::LOG_NAME, 'lesson_technical_issue_accepted',
                sprintf('Lesson %s: technical issue (%s) confirmed.', $lesson->id, $report->category->label()),
                $lesson,
                [
                    'report_id' => $report->id,
                    'previous_status' => $previous->value,
                    'new_status' => LessonReviewStatus::Accepted->value,
                    'reason' => $reason,
                    'after_finalization' => $report->after_finalization,
                ],
            );

            return $report;
        });
    }

    public function rejectTechnicalIssue(LessonTechnicalIssueReport $report, User $admin, string $reason): LessonTechnicalIssueReport
    {
        $lesson = $report->lesson;
        $this->authorizeReview($admin, $lesson);
        $this->assertReason($reason);

        return DB::transaction(function () use ($report, $admin, $reason, $lesson): LessonTechnicalIssueReport {
            $report = $this->lockReport($report);
            $previous = $report->status;

            if ($previous === LessonReviewStatus::Rejected) {
                return $report;
            }

            $this->assertOpen($previous);
            $this->settleReview($report, LessonReviewStatus::Rejected, $admin, $reason);

            // Release the automation hold when no other report supports it.
            $holdCleared = $this->releaseHoldIfUnsupported($lesson, $report);

            $this->audit->logUser($admin, self::LOG_NAME, 'lesson_technical_issue_rejected',
                sprintf('Lesson %s: technical issue (%s) rejected as unsupported.', $lesson->id, $report->category->label()),
                $lesson,
                [
                    'report_id' => $report->id,
                    'previous_status' => $previous->value,
                    'new_status' => LessonReviewStatus::Rejected->value,
                    'reason' => $reason,
                    'hold_cleared' => $holdCleared,
                ],
            );

            return $report;
        });
    }

    public function requestAdditionalEvidence(LessonAttendanceConfirmation|LessonTechnicalIssueReport $reviewable, User $admin, string $reason): LessonAttendanceConfirmation|LessonTechnicalIssueReport
    {
        $lesson = $reviewable->lesson;
        $this->authorizeReview($admin, $lesson);
        $this->assertReason($reason);

        return DB::transaction(function () use ($reviewable, $admin, $reason, $lesson): LessonAttendanceConfirmation|LessonTechnicalIssueReport {
            $reviewable = $reviewable instanceof LessonAttendanceConfirmation
                ? $this->lockConfirmation($reviewable)
                : $this->lockReport($reviewable);
            $previous = $reviewable->status;

            $this->assertOpen($previous);

            $reviewable->fill([
                'status' => LessonReviewStatus::UnderReview,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now()->toImmutable()->utc(),
                'review_reason' => $this->sanitize($reason),
            ])->save();

            $this->audit->logUser($admin, self::LOG_NAME, 'lesson_review_evidence_requested',
                sprintf('Lesson %s: additional evidence requested.', $lesson->id),
                $lesson,
                [
                    'reviewable_type' => $reviewable::class,
                    'reviewable_id' => $reviewable->id,
                    'previous_status' => $previous->value,
                    'new_status' => LessonReviewStatus::UnderReview->value,
                    'reason' => $reason,
                ],
            );

            return $reviewable;
        });
    }

    public function resolveAmbiguousEvidence(MeetingAttendanceProviderEvent $event, User $admin, LessonParticipant $participant, string $reason): MeetingAttendanceProviderEvent
    {
        $lesson = $event->lesson;

        if ($lesson === null) {
            throw new LessonException('This provider event is not linked to a lesson.');
        }

        $this->authorizeReview($admin, $lesson);
        $this->assertReason($reason);

        return DB::transaction(function () use ($event, $admin, $participant, $reason, $lesson): MeetingAttendanceProviderEvent {
            $event = MeetingAttendanceProviderEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            if ($event->processing_status !== MeetingAttendanceProcessingStatus::Review) {
                throw new LessonException('Only provider events awaiting review can be resolved.');
            }

            $results = [];

            foreach ($event->normalized_events ?? [] as $data) {
                $item = ProviderAttendanceEvent::fromArray($data);

                if ($item->joinedAt === null && $item->attendedSeconds === null) {
                    $results[] = ['event' => $item->providerEventId, 'outcome' => 'skipped', 'reason' => 'no_join_or_duration'];

                    continue;
                }

                $result = $this->attendance->record($lesson, new AttendanceEvidenceData(
                    participant: $participant,
                    source: $event->source === 'sync' ? AttendanceSource::ProviderSync : AttendanceSource::ProviderWebhook,
                    joinedAt: $item->joinedAt,
                    leftAt: $item->leftAt,
                    attendedSeconds: $item->attendedSeconds,
                    providerReference: $item->meetingReference,
                    providerEventId: implode('|', [$item->providerEventId, $item->participantKey, $item->eventType->value]),
                    metadata: [...$item->metadata, 'provider' => $item->provider, 'resolved_by_review' => true],
                ), $admin);

                $results[] = [
                    'event' => $item->providerEventId,
                    'outcome' => $result->late ? 'late' : ($result->applied ? 'applied' : 'duplicate'),
                ];
            }

            $event->fill([
                'processing_status' => MeetingAttendanceProcessingStatus::Processed,
                'status_reason' => 'resolved_by_admin',
                'results' => $results ?: null,
                'processed_at' => now()->toImmutable()->utc(),
            ])->save();

            $this->audit->logUser($admin, self::LOG_NAME, 'lesson_ambiguous_evidence_resolved',
                sprintf('Lesson %s: ambiguous provider evidence resolved as %s.', $lesson->id, $participant->label()),
                $lesson,
                [
                    'provider_event_id' => $event->id,
                    'participant' => $participant->value,
                    'previous_status' => MeetingAttendanceProcessingStatus::Review->value,
                    'new_status' => MeetingAttendanceProcessingStatus::Processed->value,
                    'reason' => $reason,
                ],
            );

            return $event;
        });
    }

    public function finalizeOutcome(Lesson $lesson, User $admin, ?LessonOutcome $outcome = null, ?string $notes = null): OutcomeFinalizationResult
    {
        $this->authorizeReview($admin, $lesson);

        return $this->outcomes->finalize($lesson, $outcome, $admin, $notes);
    }

    public function overrideOutcome(Lesson $lesson, User $admin, LessonOutcome $outcome, string $reason, ?string $notes = null): OutcomeFinalizationResult
    {
        $this->authorizeReview($admin, $lesson);

        return $this->outcomes->override($lesson, $admin, $outcome, $reason, $notes);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /** @throws AuthorizationException */
    private function authorizeReview(User $admin, Lesson $lesson): void
    {
        if (! $admin->can('reviewAttendance', $lesson)) {
            throw new AuthorizationException('You may not review attendance submissions.');
        }
    }

    /** @throws LessonException */
    private function assertReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new LessonException('A review decision requires a reason.');
        }
    }

    /** A settled review is never silently edited — a different decision conflicts. */
    private function assertOpen(LessonReviewStatus $status): void
    {
        if (! $status->isOpen()) {
            throw new LessonException(sprintf(
                'This submission was already resolved as "%s" — it cannot be changed.',
                $status->value,
            ));
        }
    }

    private function lockConfirmation(LessonAttendanceConfirmation $confirmation): LessonAttendanceConfirmation
    {
        return LessonAttendanceConfirmation::query()->whereKey($confirmation->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockReport(LessonTechnicalIssueReport $report): LessonTechnicalIssueReport
    {
        return LessonTechnicalIssueReport::query()->whereKey($report->getKey())->lockForUpdate()->firstOrFail();
    }

    private function settleReview(LessonAttendanceConfirmation|LessonTechnicalIssueReport $reviewable, LessonReviewStatus $status, User $admin, string $reason): void
    {
        $reviewable->fill([
            'status' => $status,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now()->toImmutable()->utc(),
            'review_reason' => $this->sanitize($reason),
        ])->save();
    }

    /**
     * The technical-issue hold is only released when the rejected report
     * was its last support — open or accepted reports keep it in place.
     */
    private function releaseHoldIfUnsupported(Lesson $lesson, LessonTechnicalIssueReport $rejected): bool
    {
        $record = $lesson->attendanceRecord;

        if ($record === null || $record->technical_issue_reported_at === null) {
            return false;
        }

        $stillSupported = LessonTechnicalIssueReport::query()
            ->where('lesson_id', $lesson->id)
            ->whereKeyNot($rejected->getKey())
            ->whereIn('status', [LessonReviewStatus::Pending, LessonReviewStatus::UnderReview, LessonReviewStatus::Accepted])
            ->exists();

        if ($stillSupported) {
            return false;
        }

        $record->fill(['technical_issue_reported_at' => null])->save();

        return true;
    }

    private function sanitize(string $value): string
    {
        return mb_substr(trim(strip_tags($value)), 0, 500);
    }
}
