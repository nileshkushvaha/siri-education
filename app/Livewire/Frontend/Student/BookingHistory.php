<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\Enums\BookingStatus;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class BookingHistory extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    private StudentBookingServiceInterface $bookings;

    public function boot(StudentBookingServiceInterface $bookings): void
    {
        $this->bookings = $bookings;
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $status = $this->statusFilter !== '' ? BookingStatus::from($this->statusFilter) : null;

        return view('livewire.frontend.student.booking-history', [
            'history' => $this->bookings->bookingHistory(auth()->user(), 10, $status),
            'statuses' => BookingStatus::cases(),
        ]);
    }
}
