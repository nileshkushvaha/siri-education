<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;

/**
 * Page shells for the instructor's read-only student roster; all data
 * and ownership checks live in StudentList/StudentDetail via
 * InstructorStudentService.
 */
final class InstructorStudentController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()?->hasRole('instructor'), 403);

        return view('instructor.students.index');
    }

    public function show(User $student): View
    {
        abort_unless(auth()->user()?->hasRole('instructor'), 403);

        return view('instructor.students.show', ['student' => $student]);
    }
}
