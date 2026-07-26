<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Services\Instructor\InstructorDashboardService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class DashboardOverview extends Component
{
    public int $unreadCount = 0;

    /** Phase 37B: pre-computed by PortalBadgeService/AccountPortalComposer — avoids a duplicate pendingReviewCountForTeacher() query. */
    public int $pendingHomeworkCount = 0;

    public int $upcomingCount = 0;

    public int $todayCount = 0;

    public int $completedSessions = 0;

    public float $teachingHours = 0.0;

    public int $subjectCount = 0;

    public int $waitlistDemandCount = 0;

    public int $assignedActiveLearningPlanCount = 0;

    public int $reviewDueLearningPlanCount = 0;

    public int $pendingAssessmentLearningPlanCount = 0;

    /** @var array<int, array<string, mixed>> */
    public array $nextClasses = [];

    /** @var array{pending_reviews: int, recent_submissions: array<int, array<string, mixed>>} */
    public array $homework = ['pending_reviews' => 0, 'recent_submissions' => []];

    /** @var array<int, array{currency: string, available: string, on_hold: string}> */
    public array $earnings = [];

    public bool $payoutsAvailable = false;

    /** @var array{status: mixed, missing: array<int, string>, percentage: int, next_action: string, show_prompt: bool, variant: string} */
    public array $onboarding = [
        'status' => null,
        'missing' => [],
        'percentage' => 0,
        'next_action' => 'complete_required_items',
        'show_prompt' => true,
        'variant' => 'in_progress',
    ];

    public function mount(InstructorDashboardService $dashboard): void
    {
        $summary = $dashboard->summary(auth()->user(), $this->unreadCount, $this->pendingHomeworkCount);

        $this->upcomingCount = $summary->upcomingLessons;
        $this->todayCount = $summary->todayLessons;
        $this->completedSessions = $summary->completedLessons;
        $this->teachingHours = $summary->teachingHours;
        $this->subjectCount = $summary->subjectCount;
        $this->waitlistDemandCount = $summary->waitlistDemandCount;
        $this->nextClasses = $summary->nextLessons;
        $this->assignedActiveLearningPlanCount = $summary->learningPlans['active'];
        $this->reviewDueLearningPlanCount = $summary->learningPlans['reviews_due'];
        $this->pendingAssessmentLearningPlanCount = $summary->learningPlans['assessments_due'];
        $this->homework = $summary->homework;
        $this->earnings = $summary->earnings;
        $this->payoutsAvailable = $summary->payoutsAvailable;
        $this->onboarding = $summary->onboarding;
    }

    public function render(): View
    {
        return view('livewire.frontend.instructor.dashboard-overview');
    }
}
