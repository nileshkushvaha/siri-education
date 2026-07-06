<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

final class StudentLearningPlanController extends Controller
{
    public function index(): View
    {
        return view('student.learning-plans.index');
    }
}
