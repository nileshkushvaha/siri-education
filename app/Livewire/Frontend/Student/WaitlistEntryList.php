<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Models\InstructorWaitlistEntry;
use App\Waitlist\Services\WaitlistService;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only listing plus the leave action — the student's own entries
 * only (InstructorWaitlistEntryPolicy::view() mirrors this same
 * ownership check for any other access path). Join happens from the
 * instructor's own profile page, not here.
 */
final class WaitlistEntryList extends Component
{
    use WithPagination;

    public function leave(int $entryId, WaitlistService $waitlist): void
    {
        $entry = InstructorWaitlistEntry::query()
            ->where('student_user_id', auth()->id())
            ->findOrFail($entryId);

        $waitlist->leave(auth()->user(), $entry);

        session()->flash('success', 'You left the waitlist.');
    }

    public function render(): View
    {
        return view('livewire.frontend.student.waitlist-entry-list', [
            'entries' => InstructorWaitlistEntry::query()
                ->where('student_user_id', auth()->id())
                ->with('instructor')
                ->latest('joined_at')
                ->paginate(10),
        ]);
    }
}
