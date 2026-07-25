<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Homework\Contracts\HomeworkRepositoryInterface;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Homework\Exceptions\HomeworkException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

final class HomeworkList extends Component
{
    use WithFileUploads, WithPagination;

    public ?string $submittingId = null;

    #[Validate('required|string|min:3|max:5000')]
    public string $submissionText = '';

    /** GAP-022: optional submission attachment, uploaded atomically with the text response. */
    #[Validate('nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:5120')]
    public ?TemporaryUploadedFile $submissionAttachment = null;

    private HomeworkServiceInterface $homework;

    private HomeworkRepositoryInterface $repository;

    public function boot(HomeworkServiceInterface $homework, HomeworkRepositoryInterface $repository): void
    {
        $this->homework = $homework;
        $this->repository = $repository;
    }

    public function startSubmission(string $assignmentId): void
    {
        $assignment = $this->repository->findOrFail($assignmentId);
        $this->authorize('submit', $assignment);

        $this->submittingId = $assignmentId;
        $this->submissionText = '';
        $this->submissionAttachment = null;
    }

    public function cancelSubmission(): void
    {
        $this->submittingId = null;
        $this->submissionText = '';
        $this->submissionAttachment = null;
        $this->resetValidation();
    }

    public function submit(): void
    {
        $assignment = $this->repository->findOrFail($this->submittingId);
        $this->authorize('submit', $assignment);

        $this->validate();

        try {
            $this->homework->submit($assignment, $this->submissionText, $this->submissionAttachment);
        } catch (HomeworkException $e) {
            $this->addError('submissionText', $e->getMessage());

            return;
        }

        $this->submittingId = null;
        $this->submissionText = '';
        $this->submissionAttachment = null;
        session()->flash('success', 'Homework submitted successfully.');
    }

    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.frontend.student.homework-list', [
            'assignments' => $this->homework->paginatedForStudent($user->id, 10),
            'stats' => $this->homework->statsForStudent($user->id),
        ]);
    }
}
