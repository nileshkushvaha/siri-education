<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Booking\Enums\BookingStatus;
use App\Models\Booking;
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
            ->forHost($user->id)
            ->with(['type', 'attendee'])
            ->orderBy('starts_at')
            ->get();

        $progress = Booking::query()
            ->forHost($user->id)
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
        $this->onboarding = $onboarding->progress($user);
    }

    public function render(): View
    {
        return view('livewire.frontend.instructor.dashboard-overview');
    }
}
