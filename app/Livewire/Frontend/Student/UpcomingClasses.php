<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Services\Student\StudentScheduleService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The student's current-or-upcoming lessons, grouped Today / Later, each
 * with its authoritative join action. Read in render() rather than held
 * as a public property: a polled re-render must re-apply the time filter
 * and eager loads, which a re-hydrated Eloquent collection would not.
 */
final class UpcomingClasses extends Component
{
    private StudentScheduleService $schedule;

    public function boot(StudentScheduleService $schedule): void
    {
        $this->schedule = $schedule;
    }

    public function render(): View
    {
        $rows = $this->schedule->upcoming(auth()->user());

        return view('livewire.frontend.student.upcoming-classes', [
            'classes' => $rows->pluck('booking'),
            'today' => $rows->filter(fn (array $row): bool => $row['today'])->values(),
            'later' => $rows->reject(fn (array $row): bool => $row['today'])->values(),
            'poll' => $this->schedule->poll($rows),
        ]);
    }
}
