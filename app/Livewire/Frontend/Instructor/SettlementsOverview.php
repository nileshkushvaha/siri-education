<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Models\InstructorEarning;
use App\Models\InstructorSettlementBatch;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only instructor settlement history. No mutation
 * exists here — batch creation/approval/mark-paid remain staff-only
 * actions through InstructorEarningServiceInterface. Mirrors
 * WithdrawalsManager's direct-model-query pattern: InstructorEarning
 * and InstructorSettlementBatch already carry their own forInstructor()
 * scope, so no repository indirection is needed for this bounded read.
 */
final class SettlementsOverview extends Component
{
    use WithPagination;

    public function render(): View
    {
        $instructorId = (int) auth()->id();

        // Releasable, unassigned earnings — the same pool a settlement
        // batch draws from when staff create one — grouped into one
        // aggregate row per currency.
        $pendingSettlement = InstructorEarning::query()
            ->forInstructor($instructorId)
            ->settleable()
            ->selectRaw('currency_code, SUM(earning_amount_minor) as total_minor')
            ->groupBy('currency_code')
            ->get();

        return view('livewire.frontend.instructor.settlements-overview', [
            'pendingSettlement' => $pendingSettlement,
            'settlements' => InstructorSettlementBatch::query()
                ->forInstructor($instructorId)
                ->orderByDesc('created_at')
                ->paginate(20),
        ]);
    }
}
