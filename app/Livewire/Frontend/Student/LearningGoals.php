<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Enums\LearningGoalType;
use App\Models\AcademicLevel;
use App\Models\Subject;
use App\Services\Student\StudentLearningGoalService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

final class LearningGoals extends Component
{
    public ?int $editingGoalId = null;

    public string $title = '';

    public string $type = 'academic';

    public string $subjectId = '';

    public string $academicLevelId = '';

    public string $targetDate = '';

    public string $priority = '';

    public string $description = '';

    /** @var Collection<int, Subject> */
    public Collection $subjects;

    /** @var Collection<int, AcademicLevel> */
    public Collection $academicLevels;

    public function mount(): void
    {
        $this->subjects = Subject::availableForAssignment()->orderBy('name')->get();
        $this->academicLevels = AcademicLevel::availableForAssignment()->orderBy('display_order')->orderBy('name')->get();
    }

    public function save(StudentLearningGoalService $goals): void
    {
        $data = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string'],
            'subjectId' => ['required', 'string'],
            'academicLevelId' => ['nullable', 'string'],
            'targetDate' => ['nullable', 'date', 'after:today'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:5'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'subjectId' => 'subject',
            'academicLevelId' => 'academic level',
            'targetDate' => 'target date',
        ]);

        $payload = [
            'title' => $data['title'],
            'type' => $data['type'],
            'subject_id' => $data['subjectId'],
            'academic_level_id' => $data['academicLevelId'] ?: null,
            'target_date' => $data['targetDate'] ?: null,
            'priority' => $data['priority'] ?: null,
            'description' => $data['description'] ?: null,
        ];

        if ($this->editingGoalId) {
            $goal = auth()->user()->studentLearningGoals()->findOrFail($this->editingGoalId);
            $goals->update(auth()->user(), $goal, $payload);
            session()->flash('success', 'Learning goal updated.');
        } else {
            $goals->create(auth()->user(), $payload);
            session()->flash('success', 'Learning goal created.');
        }

        $this->resetForm();
    }

    public function edit(int $goalId): void
    {
        $goal = auth()->user()->studentLearningGoals()->findOrFail($goalId);

        $this->editingGoalId = $goal->id;
        $this->title = $goal->title;
        $this->type = $goal->type->value;
        $this->subjectId = $goal->subject_id;
        $this->academicLevelId = $goal->academic_level_id ?? '';
        $this->targetDate = $goal->target_date?->format('Y-m-d') ?? '';
        $this->priority = $goal->priority ? (string) $goal->priority : '';
        $this->description = $goal->description ?? '';
    }

    public function complete(int $goalId, StudentLearningGoalService $goals): void
    {
        $goal = auth()->user()->studentLearningGoals()->findOrFail($goalId);
        $goals->complete(auth()->user(), $goal);
        session()->flash('success', 'Learning goal completed.');
    }

    public function archive(int $goalId, StudentLearningGoalService $goals): void
    {
        $goal = auth()->user()->studentLearningGoals()->findOrFail($goalId);
        $goals->archive(auth()->user(), $goal);
        session()->flash('success', 'Learning goal archived.');
    }

    public function resetForm(): void
    {
        $this->editingGoalId = null;
        $this->title = '';
        $this->type = 'academic';
        $this->subjectId = '';
        $this->academicLevelId = '';
        $this->targetDate = '';
        $this->priority = '';
        $this->description = '';
        $this->resetValidation();
    }

    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.frontend.student.learning-goals', [
            'types' => LearningGoalType::cases(),
            'activeGoals' => $user->studentLearningGoals()
                ->with(['subject', 'academicLevel'])
                ->activeForDashboard()
                ->latest()
                ->get(),
            'historicalGoals' => $user->studentLearningGoals()
                ->with(['subject', 'academicLevel'])
                ->historical()
                ->latest()
                ->get(),
        ]);
    }
}
