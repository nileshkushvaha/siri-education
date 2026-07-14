<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

final class InstructorQualityInsightsController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()?->hasRole('instructor'), 403);

        return view('instructor.quality-insights.index');
    }
}
