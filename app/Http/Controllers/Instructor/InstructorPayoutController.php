<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Page shells for the instructor payout area; all behavior lives in the
 * Livewire components, all rules in the payout/withdrawal services.
 */
final class InstructorPayoutController extends Controller
{
    public function payoutMethods(): View
    {
        abort_unless(auth()->user()?->hasRole('instructor'), 403);

        return view('instructor.payouts.methods');
    }

    public function withdrawals(): View
    {
        abort_unless(auth()->user()?->hasRole('instructor'), 403);

        return view('instructor.payouts.withdrawals');
    }
}
