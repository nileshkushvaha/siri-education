<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Enums\MeetingJoinAvailability;
use App\Feedback\Contracts\InstructorStudentFeedbackServiceInterface;
use App\Feedback\DTOs\SubmitInstructorStudentFeedbackData;
use App\Feedback\Exceptions\InstructorStudentFeedbackException;
use App\Lessons\Contracts\LessonConfirmationServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\DTOs\AttendanceConfirmationData;
use App\Lessons\DTOs\TechnicalIssueReportData;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Enums\TechnicalIssueCategory;
use App\Lessons\Exceptions\LessonException;
use App\Lessons\Summaries\Contracts\LessonSummaryRepositoryInterface;
use App\Lessons\Summaries\Contracts\LessonSummaryServiceInterface;
use App\Lessons\Summaries\Exceptions\LessonSummaryException;
use App\Models\HomeworkAssignment;
use App\Models\Lesson;
use App\Models\LessonAiSummary;
use App\Settings\MeetingSettings;
use App\Support\UserTimezoneResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Instructor's own lesson list: upcoming schedule with meeting join
 * access, lesson details, attendance confirmation, technical-issue
 * reporting, teaching-outcome confirmation, and the private
 * student-feedback form. The feedback form appears only
 * for a lesson whose finalized outcome is Completed and only until
 * the instructor's own feedback exists — once submitted it is shown
 * read-only, with no edit or delete control, matching the SRS's
 * "immutable after creation" rule. All eligibility/authorization/
 * sanitization is re-derived server-side by the injected domain
 * services on every action; nothing from the browser is trusted.
 */
final class LessonFeedbackManager extends Component
{
    use WithPagination;

    public ?string $expandedLessonId = null;

    public string $attendance_observation = '';

    public string $preparedness_observation = '';

    public string $homework_completion_observation = '';

    public string $engagement_observation = '';

    public string $learning_attitude_observation = '';

    public string $areas_needing_support = '';

    public string $private_notes = '';

    public ?string $reportingIssueId = null;

    /** The lesson whose approved-summary editor is open, if any. */
    public ?string $summaryEditingId = null;

    public string $summaryText = '';

    public string $issue_category = '';

    public string $issue_description = '';

    public function toggleExpand(string $lessonId): void
    {
        $this->expandedLessonId = $this->expandedLessonId === $lessonId ? null : $lessonId;
        $this->reportingIssueId = null;
        $this->resetForm();
    }

    public function confirmAttendance(string $lessonId, LessonConfirmationServiceInterface $confirmations): void
    {
        $lesson = Lesson::query()->forInstructor((int) auth()->id())->findOrFail($lessonId);
        $this->authorize('submitAttendance', $lesson);

        try {
            $confirmations->submitConfirmation(
                $lesson,
                auth()->user(),
                new AttendanceConfirmationData(claimedJoinedAt: now()->toImmutable()),
            );
        } catch (LessonException $e) {
            session()->flash('lessons-error', $e->getMessage());

            return;
        }

        session()->flash('lessons-status', 'Attendance confirmed. Thank you.');
    }

    public function startReportIssue(string $lessonId): void
    {
        $this->reportingIssueId = $lessonId;
        $this->issue_category = '';
        $this->issue_description = '';
        $this->resetErrorBag();
    }

    public function cancelReportIssue(): void
    {
        $this->reportingIssueId = null;
        $this->issue_category = '';
        $this->issue_description = '';
        $this->resetErrorBag();
    }

    public function submitIssueReport(string $lessonId, LessonConfirmationServiceInterface $confirmations): void
    {
        $lesson = Lesson::query()->forInstructor((int) auth()->id())->findOrFail($lessonId);
        $this->authorize('reportTechnicalIssue', $lesson);

        $this->validate([
            'issue_category' => ['required', 'string', 'in:'.implode(',', array_column(TechnicalIssueCategory::cases(), 'value'))],
            'issue_description' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $confirmations->reportTechnicalIssue(
                $lesson,
                auth()->user(),
                new TechnicalIssueReportData(
                    category: TechnicalIssueCategory::from($this->issue_category),
                    description: $this->issue_description !== '' ? $this->issue_description : null,
                ),
            );
        } catch (LessonException $e) {
            $this->addError('issue_description', $e->getMessage());

            return;
        }

        $this->reportingIssueId = null;
        $this->issue_category = '';
        $this->issue_description = '';
        session()->flash('lessons-status', 'Issue reported. Our team will review it.');
    }

    public function confirmOutcome(string $lessonId, LessonOutcomeServiceInterface $outcomes): void
    {
        $lesson = Lesson::query()->forInstructor((int) auth()->id())->findOrFail($lessonId);
        $this->authorize('complete', $lesson);

        try {
            $outcomes->finalize($lesson, actor: auth()->user());
        } catch (LessonException $e) {
            session()->flash('lessons-error', $e->getMessage());

            return;
        }

        session()->flash('lessons-status', 'Teaching outcome confirmed.');
    }

    public function submitFeedback(string $lessonId, InstructorStudentFeedbackServiceInterface $service): void
    {
        $this->validate([
            'attendance_observation' => ['nullable', 'string', 'max:1000'],
            'preparedness_observation' => ['nullable', 'string', 'max:1000'],
            'homework_completion_observation' => ['nullable', 'string', 'max:1000'],
            'engagement_observation' => ['nullable', 'string', 'max:1000'],
            'learning_attitude_observation' => ['nullable', 'string', 'max:1000'],
            'areas_needing_support' => ['nullable', 'string', 'max:1000'],
            'private_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $lesson = Lesson::query()->forInstructor((int) auth()->id())->findOrFail($lessonId);

        try {
            $service->submit(
                $lesson,
                auth()->user(),
                new SubmitInstructorStudentFeedbackData(
                    attendanceObservation: $this->attendance_observation !== '' ? $this->attendance_observation : null,
                    preparednessObservation: $this->preparedness_observation !== '' ? $this->preparedness_observation : null,
                    homeworkCompletionObservation: $this->homework_completion_observation !== '' ? $this->homework_completion_observation : null,
                    engagementObservation: $this->engagement_observation !== '' ? $this->engagement_observation : null,
                    learningAttitudeObservation: $this->learning_attitude_observation !== '' ? $this->learning_attitude_observation : null,
                    areasNeedingSupport: $this->areas_needing_support !== '' ? $this->areas_needing_support : null,
                    privateNotes: $this->private_notes !== '' ? $this->private_notes : null,
                ),
            );
        } catch (AuthorizationException|InstructorStudentFeedbackException $e) {
            $this->addError('form', $e->getMessage() ?: 'This feedback could not be submitted.');

            return;
        }

        $this->resetForm();
        $this->expandedLessonId = null;
        session()->flash('lessons-status', 'Feedback saved. It is private and visible only to you.');
    }

    // ── AI lesson summary ─────────────────────────────────────────────

    /**
     * Requests an AI DRAFT summary for a completed lesson. Queues the
     * work and returns immediately, so the page never waits on a
     * provider and keeps working when AI is unavailable.
     */
    public function generateSummary(string $lessonId, LessonSummaryServiceInterface $summaries): void
    {
        $lesson = $this->instructorLesson($lessonId);
        $this->authorize('generate', [LessonAiSummary::class, $lesson]);

        try {
            $summaries->request($lesson, auth()->user());
        } catch (LessonSummaryException $e) {
            $this->addError('aiSummary', $e->getMessage());

            return;
        } catch (\Throwable) {
            $this->addError('aiSummary', 'The AI assistant could not be reached. Please try again later.');

            return;
        }

        session()->flash('success', 'Generating a lesson summary. It will appear here in a moment.');
    }

    /**
     * Opens the editor pre-filled with the draft. Approval is a
     * separate, explicit step — nothing is recorded until the
     * instructor submits their own text.
     */
    public function startSummaryReview(string $summaryId, LessonSummaryRepositoryInterface $summaries): void
    {
        $summary = $summaries->find($summaryId);

        if ($summary === null || ! $summary->status->isActionable()) {
            return;
        }

        $this->authorize('act', $summary);

        $this->summaryEditingId = $summary->getKey();
        $this->summaryText = (string) $summary->lesson_summary;
        $this->resetValidation();
    }

    public function cancelSummaryReview(): void
    {
        $this->summaryEditingId = null;
        $this->summaryText = '';
        $this->resetValidation();
    }

    /**
     * Approves the instructor's OWN edited text as the lesson's summary
     * of record. What is stored is whatever they left in the box —
     * never the draft by default.
     */
    public function approveSummary(LessonSummaryRepositoryInterface $summaries, LessonSummaryServiceInterface $service): void
    {
        $summary = $this->summaryEditingId === null ? null : $summaries->find($this->summaryEditingId);

        if ($summary === null) {
            return;
        }

        $this->authorize('act', $summary);

        $this->validate(
            ['summaryText' => 'required|string|min:20|max:2000'],
            [],
            ['summaryText' => 'summary'],
        );

        $service->approve($summary, auth()->user(), $this->summaryText);

        $this->summaryEditingId = null;
        $this->summaryText = '';
        session()->flash('success', 'Lesson summary approved.');
    }

    public function discardSummary(string $summaryId, LessonSummaryRepositoryInterface $summaries, LessonSummaryServiceInterface $service): void
    {
        $summary = $summaries->find($summaryId);

        if ($summary === null) {
            return;
        }

        $this->authorize('act', $summary);

        $service->discard($summary, auth()->user());

        if ($this->summaryEditingId === $summaryId) {
            $this->cancelSummaryReview();
        }
    }

    /** The lesson's summary row, if any — read fresh on render so a queued run is picked up. */
    public function summaryFor(Lesson $lesson): ?LessonAiSummary
    {
        return app(LessonSummaryRepositoryInterface::class)->forLesson($lesson);
    }

    /** Re-resolved server-side and scoped to the authenticated instructor; never trusted from the browser. */
    private function instructorLesson(string $lessonId): Lesson
    {
        return Lesson::query()
            ->forInstructor((int) auth()->id())
            ->whereKey($lessonId)
            ->firstOrFail();
    }

    public function render(
        InstructorStudentFeedbackServiceInterface $service,
        BookingMeetingServiceInterface $meetings,
        MeetingSettings $meetingSettings,
    ): View {
        $instructor = auth()->user();
        $instructorId = (int) $instructor?->id;
        // The viewer IS the recipient here, so the caller — not the
        // resolver — is what reads auth(); see UserTimezoneResolver.
        $timezone = $instructor !== null
            ? UserTimezoneResolver::resolve($instructor)
            : UserTimezoneResolver::platformDefault();

        $upcoming = Lesson::query()
            ->forInstructor($instructorId)
            ->open()
            ->with(['student', 'subject', 'booking.meeting'])
            ->orderBy('starts_at')
            ->limit(20)
            ->get();

        // Bounded to the 20 upcoming lessons above; `booking.meeting` is
        // eager-loaded, so this reads the already-loaded relation and
        // never issues a query per row.
        $joinInfo = $upcoming->mapWithKeys(function (Lesson $lesson) use ($meetings, $meetingSettings): array {
            if ($lesson->booking === null) {
                return [$lesson->id => ['availability' => MeetingJoinAvailability::Unavailable, 'url' => null]];
            }

            $availability = $meetings->joinAvailabilityFor($lesson->booking, $meetingSettings->instructor_join_url_visible, $lesson->status);

            return [$lesson->id => [
                'availability' => $availability,
                'url' => $availability === MeetingJoinAvailability::Available ? $lesson->booking->meeting?->join_url : null,
            ]];
        });

        $recent = Lesson::query()
            ->forInstructor($instructorId)
            ->closed()
            ->with(['student', 'subject'])
            ->orderByDesc('starts_at')
            ->paginate(10);

        $existingFeedback = $service->existingForLessons(
            $recent->getCollection()->pluck('id')->all(),
            auth()->user(),
        );

        return view('livewire.frontend.instructor.lesson-feedback-manager', [
            'upcoming' => $upcoming,
            'joinInfo' => $joinInfo,
            'lessons' => $recent,
            'existingFeedback' => $existingFeedback,
            'completedOutcome' => LessonOutcome::Completed,
            'timezone' => $timezone,
        ]);
    }

    /** Homework tied to the lesson's booking, if any exists — display only, never queried across the full list. */
    public function homeworkStatusFor(Lesson $lesson): ?string
    {
        return HomeworkAssignment::query()
            ->where('booking_id', $lesson->booking_id)
            ->latest('created_at')
            ->first()
            ?->status->label();
    }

    private function resetForm(): void
    {
        $this->reset([
            'attendance_observation', 'preparedness_observation', 'homework_completion_observation',
            'engagement_observation', 'learning_attitude_observation', 'areas_needing_support', 'private_notes',
        ]);
        $this->resetErrorBag();
    }
}
