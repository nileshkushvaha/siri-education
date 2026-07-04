<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Models\Booking;
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

    /** @var Collection<int, Booking> */
    public Collection $nextClasses;

    public function boot(): void
    {
        $this->nextClasses = new Collection;
    }

    public function mount(StudentBookingServiceInterface $bookings, HomeworkServiceInterface $homework): void
    {
        $user = auth()->user();

        $upcoming = $bookings->upcomingClasses($user);
        $progress = $bookings->progressStats($user);
        $homeworkStats = $homework->statsForStudent($user->id);

        $this->upcomingCount = $upcoming->count();
        $this->nextClasses = $upcoming->take(3);
        $this->completedSessions = (int) $progress->completed_sessions;
        $this->totalHours = round((float) $progress->total_hours, 1);
        $this->pendingHomeworkCount = (int) ($homeworkStats->pending ?? 0);
        $this->overdueHomeworkCount = (int) ($homeworkStats->overdue ?? 0);
    }

    public function render(): View
    {
        return view('livewire.frontend.student.dashboard-overview');
    }
}
