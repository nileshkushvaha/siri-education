<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Services\Student\StudentDashboardService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class DashboardOverview extends Component
{
    public int $unreadCount = 0;

    public function render(StudentDashboardService $dashboard): View
    {
        return view('livewire.frontend.student.dashboard-overview', [
            'dashboard' => $dashboard->summary(auth()->user(), $this->unreadCount),
        ]);
    }
}
