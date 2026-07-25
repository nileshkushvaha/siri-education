<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\SupportCases;

use App\Models\SupportCase;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only — the requester's own cases only (SRS §25.37 "Students may
 * see: their own cases"; instructors likewise). Mirrors InvoiceList's
 * ownership-scoped query pattern. No action here ever writes a case —
 * creation/reply go through SupportCaseController → SupportCaseService.
 */
final class SupportCaseList extends Component
{
    use WithPagination;

    public function render(): View
    {
        $userId = auth()->id();

        return view('livewire.frontend.support-cases.support-case-list', [
            'cases' => SupportCase::query()
                ->where(function ($query) use ($userId): void {
                    $query->where('created_by', $userId)
                        ->orWhere('student_id', $userId)
                        ->orWhere('instructor_id', $userId);
                })
                ->latest('opened_at')
                ->paginate(10),
        ]);
    }
}
