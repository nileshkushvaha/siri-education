<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Booking\Enums\BookingStatus;
use App\Enums\LearningPlanStatus;
use App\Models\Booking;
use App\Models\StudentLearningPlan;
use App\Services\Instructor\InstructorOnboardingService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

final class DashboardOverview extends Component
{
    public int $upcomingCount = 0;

    public int $completedSessions = 0;

    public float $teachingHours = 0.0;

    public int $subjectCount = 0;

    public int $assignedActiveLearningPlanCount = 0;

    public int $reviewDueLearningPlanCount = 0;

    public int $pendingAssessmentLearningPlanCount = 0;

    /** @var Collection<int, Booking> */
    public Collection $nextClasses;

    /** @var array{status: mixed, missing: array<int, string>, percentage: int, next_action: string} */
    public array $onboarding = [
        'status' => null,
        'missing' => [],
        'percentage' => 0,
        'next_action' => 'complete_required_items',
    ];

    public function boot(): void
    {
        $this->nextClasses = new Collection;
    }

    public function mount(InstructorOnboardingService $onboarding): void
    {
        $user = auth()->user();

        $upcoming = Booking::query()
            ->active()
            ->upcoming()
            ->forInstructor($user->id)
            ->with(['type', 'student'])
            ->orderBy('starts_at')
            ->get();

        $progress = Booking::query()
            ->forInstructor($user->id)
            ->where('status', BookingStatus::Completed)
            ->selectRaw(
                'COUNT(*) as completed_sessions, COALESCE(SUM(TIMESTAMPDIFF(MINUTE, starts_at, ends_at)), 0) / 60.0 as teaching_hours',
            )
            ->first();

        $this->upcomingCount = $upcoming->count();
        $this->nextClasses = $upcoming->take(3);
        $this->completedSessions = (int) $progress->completed_sessions;
        $this->teachingHours = round((float) $progress->teaching_hours, 1);
        $this->subjectCount = $user->teacherSubjects()->count();
        $this->assignedActiveLearningPlanCount = StudentLearningPlan::query()
            ->where('primary_instructor_user_id', $user->id)
            ->whereIn('status', [
                LearningPlanStatus::Active->value,
                LearningPlanStatus::Paused->value,
                LearningPlanStatus::ReviewDue->value,
            ])
            ->count();
        $this->reviewDueLearningPlanCount = StudentLearningPlan::query()
            ->where('primary_instructor_user_id', $user->id)
            ->where('status', LearningPlanStatus::ReviewDue)
            ->count();
        $this->pendingAssessmentLearningPlanCount = StudentLearningPlan::query()
            ->where('primary_instructor_user_id', $user->id)
            ->where('status', LearningPlanStatus::AwaitingAssessment)
            ->whereDoesntHave('assessments')
            ->count();
        $this->onboarding = $onboarding->progress($user);
    }

    public function render(): View
    {
        return view('livewire.frontend.instructor.dashboard-overview');
    }
}
