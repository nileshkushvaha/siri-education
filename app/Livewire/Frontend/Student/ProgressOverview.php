<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Booking\Contracts\StudentBookingServiceInterface;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class ProgressOverview extends Component
{
    public function render(StudentBookingServiceInterface $bookings): View
    {
        $user = auth()->user();

        return view('livewire.frontend.student.progress-overview', [
            'stats' => $bookings->progressStats($user),
            'subjects' => $bookings->subjectBreakdown($user),
        ]);
    }
}
