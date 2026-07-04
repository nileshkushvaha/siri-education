<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Booking\Contracts\StudentBookingServiceInterface;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class PaymentHistory extends Component
{
    use WithPagination;

    private StudentBookingServiceInterface $bookings;

    public function boot(StudentBookingServiceInterface $bookings): void
    {
        $this->bookings = $bookings;
    }

    public function render(): View
    {
        return view('livewire.frontend.student.payment-history', [
            'payments' => $this->bookings->paymentHistory(auth()->user(), 10),
        ]);
    }
}
