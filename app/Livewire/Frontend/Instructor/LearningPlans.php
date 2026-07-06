<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Models\StudentLearningPlan;
use App\Services\Student\LearningPlanService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class LearningPlans extends Component
{
    public ?int $selectedPlanId = null;

    public string $assessmentNotes = '';

    public string $recommendedFocus = '';

    public string $milestoneTitle = '';

    public string $reviewSummary = '';

    public function recordAssessment(LearningPlanService $plans): void
    {
        $plan = $this->selectedPlan();
        $plans->recordAssessment(auth()->user(), $plan, [
            'assessment_type' => 'initial',
            'recommended_focus' => $this->recommendedFocus,
            'notes' => $this->assessmentNotes,
        ]);
        session()->flash('success', 'Assessment recorded.');
        $this->reset(['assessmentNotes', 'recommendedFocus']);
    }

    public function createMilestone(LearningPlanService $plans): void
    {
        $plan = $this->selectedPlan();
        $plans->createMilestone(auth()->user(), $plan, ['title' => $this->milestoneTitle]);
        session()->flash('success', 'Milestone created.');
        $this->reset('milestoneTitle');
    }

    public function createReview(LearningPlanService $plans): void
    {
        $plan = $this->selectedPlan();
        $plans->createReview(auth()->user(), $plan, ['summary' => $this->reviewSummary]);
        session()->flash('success', 'Progress review created.');
        $this->reset('reviewSummary');
    }

    public function render(): View
    {
        $plans = auth()->user()->assignedLearningPlans()
            ->with(['student', 'subject', 'academicLevel', 'milestones', 'assessments', 'reviews'])
            ->activeForDashboard()
            ->latest()
            ->get();

        $this->selectedPlanId ??= $plans->first()?->id;

        return view('livewire.frontend.instructor.learning-plans', [
            'plans' => $plans,
            'selectedPlan' => $this->selectedPlanId ? $plans->firstWhere('id', $this->selectedPlanId) : null,
        ]);
    }

    private function selectedPlan(): StudentLearningPlan
    {
        return auth()->user()->assignedLearningPlans()->findOrFail($this->selectedPlanId);
    }
}
