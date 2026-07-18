<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Page shells for the instructor's read-only finance views; all data
 * comes from the Livewire components, all authorization from the
 * existing InstructorEarning/InstructorSettlementBatch policies.
 */
final class InstructorFinanceController extends Controller
{
    public function earnings(): View
    {
        abort_unless(auth()->user()?->hasRole('instructor'), 403);

        return view('instructor.earnings.index');
    }

    public function settlements(): View
    {
        abort_unless(auth()->user()?->hasRole('instructor'), 403);

        return view('instructor.settlements.index');
    }
}
