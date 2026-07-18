<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Page shell for the instructor's self-service vacation toggle; all
 * behavior lives in VacationModeManager, all lifecycle rules in
 * InstructorOnboardingService::setVacation()/resumeFromVacation().
 */
final class InstructorVacationController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()?->hasRole('instructor'), 403);

        return view('instructor.vacation.index');
    }
}
