<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

final class InstructorLearningPlanController extends Controller
{
    public function index(): View
    {
        return view('instructor.learning-plans.index');
    }
}
