<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Models\Booking;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\User;
use App\Services\Student\StudentDashboardService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

final class DashboardOverview extends Component
{
    public int $upcomingCount = 0;

    public int $completedSessions = 0;

    public float $totalHours = 0.0;

    public int $pendingHomeworkCount = 0;

    public int $overdueHomeworkCount = 0;

    public int $profileCompletion = 0;

    public int $favoriteInstructorCount = 0;

    public int $bookableFavoriteInstructorCount = 0;

    public int $activeLearningPlanCount = 0;

    public string $recommendedNextAction = 'complete_profile';

    /** @var Collection<int, Booking> */
    public Collection $nextClasses;

    /** @var Collection<int, StudentLearningGoal> */
    public Collection $activeGoals;

    /** @var Collection<int, StudentLearningPlan> */
    public Collection $activeLearningPlans;

    public ?StudentLearningPlan $currentLearningPlan = null;

    /** @var Collection<int, Subject> */
    public Collection $preferredSubjects;

    /** @var Collection<int, User> */
    public Collection $favoriteInstructors;

    /** @var array<int, string> */
    public array $profileMissingItems = [];

    /** @var array<string, string> */
    public array $safePlaceholders = [];

    /** @var array<string, mixed>|null */
    public ?array $wallet = null;

    public function boot(): void
    {
        $this->nextClasses = new Collection;
        $this->activeGoals = new Collection;
        $this->activeLearningPlans = new Collection;
        $this->preferredSubjects = new Collection;
        $this->favoriteInstructors = new Collection;
    }

    public function mount(StudentDashboardService $dashboard): void
    {
        $summary = $dashboard->summary(auth()->user());

        $this->upcomingCount = $summary['upcoming_count'];
        $this->nextClasses = $summary['next_classes'];
        $this->completedSessions = $summary['completed_sessions'];
        $this->totalHours = $summary['total_hours'];
        $this->pendingHomeworkCount = $summary['pending_homework_count'];
        $this->overdueHomeworkCount = $summary['overdue_homework_count'];
        $this->profileCompletion = $summary['profile_completion'];
        $this->profileMissingItems = $summary['profile_missing_items'];
        $this->preferredSubjects = $summary['preferred_subjects'];
        $this->activeGoals = $summary['active_goals'];
        $this->activeLearningPlans = $summary['active_learning_plans'];
        $this->activeLearningPlanCount = $summary['active_learning_plan_count'];
        $this->currentLearningPlan = $summary['current_learning_plan'];
        $this->favoriteInstructors = $summary['favorite_instructors'];
        $this->favoriteInstructorCount = $summary['favorite_instructor_count'];
        $this->bookableFavoriteInstructorCount = $summary['bookable_favorite_instructor_count'];
        $this->safePlaceholders = $summary['safe_placeholders'];
        $this->wallet = $summary['wallet'];
        $this->recommendedNextAction = $summary['recommended_next_action'];
    }

    public function render(): View
    {
        return view('livewire.frontend.student.dashboard-overview');
    }
}
