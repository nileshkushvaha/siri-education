<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Models\User;
use App\Services\Instructor\InstructorStudentService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Read-only instructor-scoped student detail. Not a new
 * student profile — only the instructor's own teaching-relationship
 * summary with this student, reusing InstructorStudentService. A
 * student the instructor never taught 404s here; there is no separate
 * policy because ownership is the relationship itself (Lesson rows).
 */
final class StudentDetail extends Component
{
    public int $studentId;

    private InstructorStudentService $students;

    public function boot(InstructorStudentService $students): void
    {
        $this->students = $students;
    }

    public function mount(User $student): void
    {
        abort_unless($this->students->hasRelationship((int) auth()->id(), $student->id), 404);

        $this->studentId = $student->id;
    }

    public function render(): View
    {
        $instructorId = (int) auth()->id();

        return view('livewire.frontend.instructor.student-detail', [
            'summary' => $this->students->summaryFor($instructorId, $this->studentId),
            'recentLessons' => $this->students->recentLessonsFor($instructorId, $this->studentId),
        ]);
    }
}
