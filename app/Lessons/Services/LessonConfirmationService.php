<?php

declare(strict_types=1);

namespace App\Lessons\Services;

use App\Booking\Enums\BookingStatus;
use App\Lessons\Contracts\LessonAttendanceServiceInterface;
use App\Lessons\Contracts\LessonConfirmationServiceInterface;
use App\Lessons\DTOs\AttendanceConfirmationData;
use App\Lessons\DTOs\AttendanceEvidenceData;
use App\Lessons\DTOs\TechnicalIssueReportData;
use App\Lessons\Enums\AttendanceSource;
use App\Lessons\Enums\LessonParticipant;
use App\Lessons\Enums\LessonReviewStatus;
use App\Lessons\Exceptions\LessonException;
use App\Models\Lesson;
use App\Models\LessonAttendanceConfirmation;
use App\Models\LessonTechnicalIssueReport;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Settings\LessonSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Participant attendance claims and technical-issue reports. Every
 * identity decision comes from stored data (the lesson's participants);
 * nothing here writes attendance aggregates or outcomes — in-window
 * technical issues place their hold through LessonAttendanceService's
 * evidence pathway, and attendance claims wait for admin review.
 */
final class LessonConfirmationService implements LessonConfirmationServiceInterface
{
    private const string LOG_NAME = 'lessons';

    public function __construct(
        private readonly LessonAttendanceServiceInterface $attendance,
        private readonly AuditTrailService $audit,
        private readonly LessonSettings $settings,
    ) {}

    public function submitConfirmation(Lesson $lesson, User $user, AttendanceConfirmationData $data): LessonAttendanceConfirmation
    {
        $participant = $this->assertParticipant($lesson, $user, 'submitAttendance');
        $this->assertLessonAcceptsSubmissions($lesson);

        if (! $data->hasClaim()) {
            throw new LessonException('An attendance confirmation needs a claimed join time or attended minutes.');
        }

        if ($data->claimedJoinedAt !== null && $data->claimedLeftAt !== null
            && $data->claimedLeftAt->isBefore($data->claimedJoinedAt)) {
            throw new LessonException('The claimed leave time cannot be before the claimed join time.');
        }

        return DB::transaction(function () use ($lesson, $user, $participant, $data): LessonAttendanceConfirmation {
            $fingerprint = hash('sha256', $data->fingerprintBasis());

            $existing = LessonAttendanceConfirmation::query()
                ->where('lesson_id', $lesson->id)
                ->where('participant', $participant)
                ->where('fingerprint', $fingerprint)
                ->first();

            if ($existing !== null) {
                return $existing; // identical resubmission — idempotent
            }

            $confirmation = LessonAttendanceConfirmation::query()->create([
                'lesson_id' => $lesson->id,
                'participant' => $participant,
                'submitted_by' => $user->id,
                'claimed_joined_at' => $data->claimedJoinedAt?->utc(),
                'claimed_left_at' => $data->claimedLeftAt?->utc(),
                'claimed_attended_minutes' => $data->claimedAttendedMinutes,
                'notes' => $this->sanitize($data->notes),
                'fingerprint' => $fingerprint,
                'status' => LessonReviewStatus::Pending,
                'submitted_at' => now()->toImmutable()->utc(),
            ]);

            $this->audit->logUser(
                $user,
                self::LOG_NAME,
                'lesson_attendance_confirmation_submitted',
                sprintf('Lesson %s: %s submitted an attendance confirmation.', $lesson->id, $participant->label()),
                $lesson,
                [
                    'confirmation_id' => $confirmation->id,
                    'participant' => $participant->value,
                    'booking_id' => $lesson->booking_id,
                ],
            );

            return $confirmation;
        });
    }

    public function reportTechnicalIssue(Lesson $lesson, User $user, TechnicalIssueReportData $data): LessonTechnicalIssueReport
    {
        $participant = $this->assertParticipant($lesson, $user, 'reportTechnicalIssue');
        $this->assertLessonAcceptsSubmissions($lesson);

        $windowClosesAt = $lesson->ends_at->addMinutes(max(0, $this->settings->technical_issue_window_minutes));
        $flaggedLate = now()->isAfter($windowClosesAt);
        $afterFinalization = $lesson->hasFinalizedOutcome();

        $report = DB::transaction(function () use ($lesson, $user, $participant, $data, $flaggedLate, $afterFinalization): LessonTechnicalIssueReport {
            $report = LessonTechnicalIssueReport::query()->create([
                'lesson_id' => $lesson->id,
                'booking_meeting_id' => $lesson->booking?->meeting?->id,
                'reporter' => $participant,
                'reported_by' => $user->id,
                'category' => $data->category,
                'description' => $this->sanitize($data->description),
                'occurred_at' => $data->occurredAt?->utc(),
                'status' => LessonReviewStatus::Pending,
                'flagged_late' => $flaggedLate,
                'after_finalization' => $afterFinalization,
                'submitted_at' => now()->toImmutable()->utc(),
            ]);

            // Only a valid in-window report before finalization places the
            // automation hold; anything else waits for a human decision so
            // it can never hijack or rewrite an existing outcome.
            if (! $flaggedLate && ! $afterFinalization) {
                $this->attendance->record($lesson, new AttendanceEvidenceData(
                    participant: $participant,
                    source: $this->confirmationSourceFor($participant),
                    providerEventId: 'tech-issue:'.$report->id,
                    metadata: ['technical_issue_category' => $data->category->value],
                    technicalIssueReported: true,
                ), $user);
            }

            return $report;
        });

        $this->audit->logUser(
            $user,
            self::LOG_NAME,
            'lesson_technical_issue_reported',
            sprintf('Lesson %s: %s reported a technical issue (%s).', $lesson->id, $participant->label(), $data->category->label()),
            $lesson,
            [
                'report_id' => $report->id,
                'category' => $data->category->value,
                'flagged_late' => $flaggedLate,
                'after_finalization' => $afterFinalization,
                'booking_id' => $lesson->booking_id,
                'booking_meeting_id' => $report->booking_meeting_id,
            ],
        );

        return $report;
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /** @throws AuthorizationException */
    private function assertParticipant(Lesson $lesson, User $user, string $ability): LessonParticipant
    {
        if (! $user->can($ability, $lesson)) {
            throw new AuthorizationException('You may not submit for this lesson.');
        }

        // The role is derived from the lesson's stored participants —
        // request-supplied roles or user ids are never trusted.
        return LessonParticipant::forUser($lesson, $user)
            ?? throw new AuthorizationException('You are not a participant of this lesson.');
    }

    /** @throws LessonException */
    private function assertLessonAcceptsSubmissions(Lesson $lesson): void
    {
        if ($lesson->booking?->status === BookingStatus::Cancelled
            || $lesson->status->isTerminal()) {
            throw new LessonException('A cancelled lesson does not accept attendance submissions.');
        }

        if ($lesson->starts_at->isFuture()) {
            throw new LessonException('Attendance can only be submitted after the lesson has started.');
        }
    }

    private function confirmationSourceFor(LessonParticipant $participant): AttendanceSource
    {
        return $participant === LessonParticipant::Instructor
            ? AttendanceSource::InstructorConfirmation
            : AttendanceSource::StudentConfirmation;
    }

    private function sanitize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return mb_substr(trim(strip_tags($value)), 0, 1000);
    }
}
