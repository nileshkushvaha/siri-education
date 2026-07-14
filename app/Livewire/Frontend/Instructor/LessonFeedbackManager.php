<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Feedback\Contracts\InstructorStudentFeedbackServiceInterface;
use App\Feedback\DTOs\SubmitInstructorStudentFeedbackData;
use App\Feedback\Exceptions\InstructorStudentFeedbackException;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Lesson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Instructor's own lesson list, hosting the private student-feedback
 * form (Phase 17Q). The feedback form appears only for a lesson whose
 * finalized outcome is Completed and only until the instructor's own
 * feedback exists — once submitted it is shown read-only, with no
 * edit or delete control, matching the SRS's "immutable after
 * creation" rule. All eligibility/authorization/sanitization is
 * re-derived server-side by InstructorStudentFeedbackServiceInterface
 * on every submission; nothing from the browser is trusted.
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

    public function toggleExpand(string $lessonId): void
    {
        $this->expandedLessonId = $this->expandedLessonId === $lessonId ? null : $lessonId;
        $this->resetForm();
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

    public function render(InstructorStudentFeedbackServiceInterface $service): View
    {
        $lessons = Lesson::query()
            ->forInstructor((int) auth()->id())
            ->with(['student', 'subject'])
            ->orderByDesc('starts_at')
            ->paginate(10);

        $existingFeedback = $service->existingForLessons(
            $lessons->getCollection()->pluck('id')->all(),
            auth()->user(),
        );

        return view('livewire.frontend.instructor.lesson-feedback-manager', [
            'lessons' => $lessons,
            'existingFeedback' => $existingFeedback,
            'completedOutcome' => LessonOutcome::Completed,
        ]);
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
