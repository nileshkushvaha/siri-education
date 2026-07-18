<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Page shell for the instructor's read-only analytics foundation; all
 * data comes from AnalyticsOverview via InstructorAnalyticsService.
 */
final class InstructorAnalyticsController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()?->hasRole('instructor'), 403);

        return view('instructor.analytics.index');
    }
}
