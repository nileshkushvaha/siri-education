<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Earnings\Contracts\InstructorEarningRepositoryInterface;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only instructor earnings history. No mutation
 * exists here — release/reverse/settlement remain staff-only actions
 * through InstructorEarningServiceInterface. Purely a bounded,
 * eager-loaded view over InstructorEarningRepositoryInterface, scoped
 * to the authenticated instructor's own earnings.
 */
final class EarningsOverview extends Component
{
    use WithPagination;

    private InstructorEarningRepositoryInterface $earnings;

    public function boot(InstructorEarningRepositoryInterface $earnings): void
    {
        $this->earnings = $earnings;
    }

    public function render(): View
    {
        $instructorId = (int) auth()->id();

        return view('livewire.frontend.instructor.earnings-overview', [
            'summary' => $this->earnings->summaryForInstructor($instructorId),
            'earnings' => $this->earnings->paginatedForInstructor($instructorId, 20),
        ]);
    }
}
