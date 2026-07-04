<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Models\Booking;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

final class UpcomingClasses extends Component
{
    /** @var Collection<int, Booking> */
    public Collection $classes;

    public function boot(): void
    {
        $this->classes = new Collection;
    }

    public function mount(StudentBookingServiceInterface $bookings): void
    {
        $this->classes = $bookings->upcomingClasses(auth()->user());
    }

    public function render(): View
    {
        return view('livewire.frontend.student.upcoming-classes');
    }
}
