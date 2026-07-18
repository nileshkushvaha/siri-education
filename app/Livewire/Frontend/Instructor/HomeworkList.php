<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Homework\Contracts\HomeworkRepositoryInterface;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Homework\Exceptions\HomeworkException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

final class HomeworkList extends Component
{
    use WithPagination;

    public ?string $reviewingId = null;

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
        ]);
    }
}
