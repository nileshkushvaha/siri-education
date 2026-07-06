<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Services\Student\LearningPlanService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class LearningPlans extends Component
{
    public function createFromGoal(int $goalId, LearningPlanService $plans): void
    {
        $goal = auth()->user()->studentLearningGoals()->findOrFail($goalId);
        $plans->createDraftFromGoal(auth()->user(), $goal);
        session()->flash('success', 'Learning plan draft created.');
    }

    public function render(): View
    {
        $user = auth()->user();

        $openGoalIds = $user->studentLearningPlans()
            ->whereNotIn('status', ['completed', 'archived'])
            ->pluck('learning_goal_id')
            ->all();

        return view('livewire.frontend.student.learning-plans', [
            'activePlans' => $user->studentLearningPlans()
                ->with(['learningGoal', 'subject', 'academicLevel', 'primaryInstructor', 'milestones', 'assessments', 'reviews'])
                ->activeForDashboard()
                ->latest()
                ->get(),
            'historicalPlans' => $user->studentLearningPlans()
                ->with(['subject', 'primaryInstructor'])
                ->historical()
                ->latest()
                ->get(),
            'availableGoals' => $user->studentLearningGoals()
                ->with(['subject', 'academicLevel'])
                ->activeForDashboard()
                ->whereNotIn('id', $openGoalIds)
                ->latest()
                ->get(),
        ]);
    }
}
