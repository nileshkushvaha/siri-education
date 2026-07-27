<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Enums\AcademicStatus;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Homework\Enums\HomeworkStatus;
use App\Homework\Exceptions\HomeworkException;
use App\Models\AcademicLevel;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkResource;
use App\Models\HomeworkResourceVersion;
use App\Models\Subject;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * SRS §7.15 "Resource Library": an instructor's own
 * categorized, searchable, versioned resources. Distinct from
 * direct HomeworkAssignment attachments — those remain one-off
 * and are managed from HomeworkList, not here.
 */
final class HomeworkResourceLibrary extends Component
{
    use WithFileUploads, WithPagination;

    public string $search = '';

    public string $statusFilter = 'active';

    public bool $showCreateForm = false;

    #[Validate('required|string|min:3|max:150')]
    public string $createTitle = '';

    #[Validate('nullable|string|max:2000')]
    public string $createDescription = '';

    public ?string $createSubjectId = null;

    public ?string $createAcademicLevelId = null;

    public ?string $publishingResourceId = null;

    #[Validate('nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:5120')]
    public ?TemporaryUploadedFile $newVersionFile = null;

    public ?string $expandedResourceId = null;

    public ?string $attachingVersionId = null;

    public ?string $attachAssignmentId = null;

    private HomeworkServiceInterface $homework;

    public function boot(HomeworkServiceInterface $homework): void
    {
        $this->homework = $homework;
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openCreateForm(): void
    {
        $this->authorize('create', HomeworkResource::class);
        $this->showCreateForm = true;
    }

    public function cancelCreate(): void
    {
        $this->resetCreateForm();
    }

    public function createResource(): void
    {
        $this->authorize('create', HomeworkResource::class);

        $this->validate();

        try {
            $this->homework->createResource(auth()->user(), [
                'title' => trim($this->createTitle),
                'description' => trim($this->createDescription) !== '' ? trim($this->createDescription) : null,
                'subject_id' => $this->createSubjectId,
                'academic_level_id' => $this->createAcademicLevelId,
            ]);
        } catch (HomeworkException $e) {
            $this->addError('createSubjectId', $e->getMessage());

            return;
        }

        $this->resetCreateForm();
        session()->flash('success', 'Resource created.');
    }

    private function resetCreateForm(): void
    {
        $this->showCreateForm = false;
        $this->createTitle = '';
        $this->createDescription = '';
        $this->createSubjectId = null;
        $this->createAcademicLevelId = null;
        $this->resetValidation();
    }

    public function toggleHistory(string $resourceId): void
    {
        $this->expandedResourceId = $this->expandedResourceId === $resourceId ? null : $resourceId;
    }

    public function startPublish(string $resourceId): void
    {
        $this->publishingResourceId = $resourceId;
        $this->newVersionFile = null;
        $this->resetValidation();
    }

    public function cancelPublish(): void
    {
        $this->publishingResourceId = null;
        $this->newVersionFile = null;
        $this->resetValidation();
    }

    public function publishVersion(string $resourceId): void
    {
        $resource = HomeworkResource::query()->findOrFail($resourceId);
        $this->authorize('update', $resource);

        $this->validateOnly('newVersionFile');

        if (! $this->newVersionFile instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            $this->homework->publishResourceVersion(auth()->user(), $resource, $this->newVersionFile);
        } catch (HomeworkException $e) {
            $this->addError('newVersionFile', $e->getMessage());

            return;
        }

        $this->publishingResourceId = null;
        $this->newVersionFile = null;
        $this->expandedResourceId = $resourceId;
        session()->flash('success', 'New version published.');
    }

    public function archiveResource(string $resourceId): void
    {
        $resource = HomeworkResource::query()->findOrFail($resourceId);
        $this->authorize('update', $resource);

        $this->homework->archiveResource(auth()->user(), $resource);
        session()->flash('success', 'Resource archived.');
    }

    public function startAttach(string $versionId): void
    {
        $this->attachingVersionId = $versionId;
        $this->attachAssignmentId = null;
        $this->resetValidation();
    }

    public function cancelAttach(): void
    {
        $this->attachingVersionId = null;
        $this->attachAssignmentId = null;
        $this->resetValidation();
    }

    public function attachToAssignment(): void
    {
        if ($this->attachingVersionId === null || $this->attachAssignmentId === null) {
            $this->addError('attachAssignmentId', 'Choose an assignment.');

            return;
        }

        $version = HomeworkResourceVersion::query()->findOrFail($this->attachingVersionId);
        $assignment = HomeworkAssignment::query()->findOrFail($this->attachAssignmentId);

        try {
            $this->homework->attachResourceVersion(auth()->user(), $assignment, $version);
        } catch (HomeworkException|AuthorizationException $e) {
            $this->addError('attachAssignmentId', $e->getMessage());

            return;
        }

        $this->attachingVersionId = null;
        $this->attachAssignmentId = null;
        session()->flash('success', 'Resource attached to the assignment.');
    }

    public function render(): View
    {
        $teacherId = auth()->id();

        return view('livewire.frontend.instructor.homework-resource-library', [
            'resources' => $this->resources($teacherId),
            'subjectOptions' => $this->showCreateForm ? $this->subjectOptions() : collect(),
            'academicLevelOptions' => $this->showCreateForm ? $this->academicLevelOptions() : collect(),
            'assignableAssignments' => $this->attachingVersionId !== null ? $this->assignableAssignments($teacherId) : collect(),
        ]);
    }

    private function resources(int $teacherId): LengthAwarePaginator
    {
        return HomeworkResource::query()
            ->forInstructor($teacherId)
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->when(trim($this->search) !== '', fn ($q) => $q->where('title', 'like', '%'.trim($this->search).'%'))
            ->with(['subject', 'academicLevel', 'versions.media'])
            ->latest('updated_at')
            ->paginate(10);
    }

    /** @return Collection<string, string> */
    private function subjectOptions(): Collection
    {
        return Subject::query()->where('status', AcademicStatus::Active)->orderBy('name')->pluck('name', 'id');
    }

    /** @return Collection<string, string> */
    private function academicLevelOptions(): Collection
    {
        return AcademicLevel::query()->where('status', AcademicStatus::Active)->orderBy('name')->pluck('name', 'id');
    }

    /**
     * Assignments this instructor can still attach a resource to —
     * mutability (graded/plan-archived) is re-checked authoritatively by
     * HomeworkService::attachResourceVersion() regardless; this list is
     * a convenience filter only.
     *
     * @return Collection<string, string>
     */
    private function assignableAssignments(int $teacherId): Collection
    {
        return HomeworkAssignment::query()
            ->forTeacher($teacherId)
            ->where('status', '!=', HomeworkStatus::Graded->value)
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (HomeworkAssignment $a): array => [
                $a->id => sprintf('%s · %s', $a->title, $a->student?->name ?? 'Student'),
            ]);
    }
}
