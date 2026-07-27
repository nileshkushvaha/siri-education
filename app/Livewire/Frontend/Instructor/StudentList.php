<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Services\Instructor\InstructorStudentService;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only roster of students the instructor has an actual teaching
 * relationship with, derived entirely from Lesson. No
 * messaging, notes, grading, or analytics — those are out of scope here.
 */
final class StudentList extends Component
{
    use WithPagination;

    private InstructorStudentService $students;

    public function boot(InstructorStudentService $students): void
    {
        $this->students = $students;
    }

    public function render(): View
    {
        return view('livewire.frontend.instructor.student-list', [
            'students' => $this->students->paginatedForInstructor((int) auth()->id(), 20),
        ]);
    }
}
