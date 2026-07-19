<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Booking\Enums\BookingStatus;
use App\Enums\LearningPlanStatus;
use App\Homework\Contracts\HomeworkRepositoryInterface;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Homework\Exceptions\HomeworkException;
use App\Models\Booking;
use App\Models\HomeworkAssignment;
use App\Models\StudentLearningPlan;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

final class HomeworkList extends Component
{
    use WithPagination;

    public ?string $reviewingId = null;

    public bool $showAssignForm = false;

    public ?int $assignStudentId = null;

    public ?string $assignBookingId = null;

    public ?int $assignPlanId = null;

    public string $assignTitle = '';

    public string $assignSubject = '';

    public string $assignDescription = '';

    public string $assignDueAt = '';

    #[Validate('required|string|min:3|max:5000')]
    public string $feedbackText = '';

    #[Validate('nullable|string|max:20')]
    public string $grade = '';

    private HomeworkServiceInterface $homework;

    private HomeworkRepositoryInterface $repository;

    public function boot(HomeworkServiceInterface $homework, HomeworkRepositoryInterface $repository): void
    {
        $this->homework = $homework;
        $this->repository = $repository;
    }

    public function openAssignForm(): void
    {
        $this->authorize('create', HomeworkAssignment::class);

        $this->showAssignForm = true;
        $this->resetAssignForm(keepOpen: true);
    }

    public function cancelAssign(): void
    {
        $this->resetAssignForm();
    }

    public function updatedAssignStudentId(): void
    {
        // Context options are per-student; never carry a selection across.
        $this->assignBookingId = null;
        $this->assignPlanId = null;
        $this->resetValidation();
    }

    public function assign(): void
    {
        $this->authorize('create', HomeworkAssignment::class);

        $this->validate([
            'assignStudentId' => 'required|integer',
            'assignTitle' => 'required|string|min:3|max:150',
            'assignSubject' => 'required|string|max:100',
            'assignDescription' => 'nullable|string|max:5000',
            'assignDueAt' => 'required|date|after:now',
        ], [], [
            'assignStudentId' => 'student',
            'assignTitle' => 'title',
            'assignSubject' => 'subject',
            'assignDescription' => 'description',
            'assignDueAt' => 'due date',
        ]);

        $student = User::query()->find($this->assignStudentId);

        if ($student === null) {
            $this->addError('assignStudentId', 'Choose a student.');

            return;
        }

        try {
            $this->homework->assign(
                auth()->user(),
                $student,
                [
                    'title' => trim($this->assignTitle),
                    'subject' => trim($this->assignSubject),
                    'description' => trim($this->assignDescription) !== '' ? trim($this->assignDescription) : null,
                    'due_at' => $this->assignDueAt,
                ],
                $this->assignBookingId !== '' ? $this->assignBookingId : null,
                $this->assignPlanId,
            );
        } catch (HomeworkException $e) {
            // Selections are Livewire properties, so they survive the
            // failure for correction (Step 9: preserve selections).
            $this->addError('assignContext', $e->getMessage());

            return;
        }

        $this->resetAssignForm();
        session()->flash('success', 'Homework assigned successfully.');
    }

    private function resetAssignForm(bool $keepOpen = false): void
    {
        $this->showAssignForm = $keepOpen;
        $this->assignStudentId = null;
        $this->assignBookingId = null;
        $this->assignPlanId = null;
        $this->assignTitle = '';
        $this->assignSubject = '';
        $this->assignDescription = '';
        $this->assignDueAt = '';
        $this->resetValidation();
    }

    public function startReview(string $assignmentId): void
    {
        $assignment = $this->repository->findOrFail($assignmentId);
        $this->authorize('review', $assignment);

        $this->reviewingId = $assignmentId;
        $this->feedbackText = '';
        $this->grade = '';
        $this->resetValidation();
    }

    public function cancelReview(): void
    {
        $this->reviewingId = null;
        $this->feedbackText = '';
        $this->grade = '';
        $this->resetValidation();
    }

    public function submitReview(): void
    {
        $assignment = $this->repository->findOrFail($this->reviewingId);
        $this->authorize('review', $assignment);

        $this->validate();

        try {
            $this->homework->review($assignment, $this->feedbackText, $this->grade !== '' ? $this->grade : null);
        } catch (HomeworkException $e) {
            $this->addError('feedbackText', $e->getMessage());

            return;
        }

        $this->reviewingId = null;
        $this->feedbackText = '';
        $this->grade = '';
        session()->flash('success', 'Homework reviewed successfully.');
    }

    public function render(): View
    {
        $teacherId = auth()->id();

        return view('livewire.frontend.instructor.homework-list', [
            'pending' => $this->homework->paginatedForTeacher($teacherId, 20),
            'recentlyGraded' => $this->homework->recentlyGradedForTeacher($teacherId, 10),
            'studentOptions' => $this->showAssignForm ? $this->studentOptions($teacherId) : collect(),
            'bookingOptions' => $this->showAssignForm ? $this->bookingOptions($teacherId) : collect(),
            'planOptions' => $this->showAssignForm ? $this->planOptions($teacherId) : collect(),
        ]);
    }

    /**
     * Phase 24J — GAP-021 Step 9: options are scoped to the authorized
     * instructor's own relationships; another student's bookings/plans
     * are never listed. The service re-validates ownership regardless.
     *
     * @return Collection<int, string>
     */
    private function studentOptions(int $teacherId): Collection
    {
        $studentIds = Booking::query()
            ->where('instructor_id', $teacherId)
            ->where('status', BookingStatus::Completed)
            ->distinct()
            ->pluck('student_id')
            ->merge(
                StudentLearningPlan::query()
                    ->where('primary_instructor_user_id', $teacherId)
                    ->whereNotIn('status', [LearningPlanStatus::Completed->value, LearningPlanStatus::Archived->value])
                    ->pluck('student_user_id'),
            )
            ->unique();

        return User::query()->whereIn('id', $studentIds)->orderBy('name')->pluck('name', 'id');
    }

    /** @return Collection<string, string> */
    private function bookingOptions(int $teacherId): Collection
    {
        if ($this->assignStudentId === null) {
            return collect();
        }

        return Booking::query()
            ->where('instructor_id', $teacherId)
            ->where('student_id', $this->assignStudentId)
            ->where('status', BookingStatus::Completed)
            ->with('type')
            ->latest('starts_at')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Booking $booking): array => [
                $booking->id => trim(sprintf(
                    '%s · %s (%s)',
                    $booking->starts_at->timezone($booking->timezone ?? 'UTC')->format('M j, Y g:i A'),
                    $booking->type?->name ?? 'Lesson',
                    $booking->reference,
                )),
            ]);
    }

    /** @return Collection<int, string> */
    private function planOptions(int $teacherId): Collection
    {
        if ($this->assignStudentId === null) {
            return collect();
        }

        return StudentLearningPlan::query()
            ->where('primary_instructor_user_id', $teacherId)
            ->where('student_user_id', $this->assignStudentId)
            ->whereNotIn('status', [LearningPlanStatus::Completed->value, LearningPlanStatus::Archived->value])
            ->with('subject')
            ->orderBy('title')
            ->get()
            ->mapWithKeys(fn (StudentLearningPlan $plan): array => [
                $plan->id => trim($plan->title.($plan->subject !== null ? ' · '.$plan->subject->name : '')),
            ]);
    }
}
