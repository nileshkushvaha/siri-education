<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Models\Invoice;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only — the student's own invoices only (InvoicePolicy::view()
 * mirrors this same ownership check for the download route). No
 * action here ever creates, edits, or deletes an invoice.
 */
final class InvoiceList extends Component
{
    use WithPagination;

    public function render(): View
    {
        return view('livewire.frontend.student.invoice-list', [
            'invoices' => Invoice::query()
                ->where('user_id', auth()->id())
                ->latest('issued_at')
                ->paginate(10),
        ]);
    }
}
