<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Enums\WaitlistEntryStatus;
use App\Http\Controllers\Controller;
use App\Models\InstructorWaitlistEntry;
use App\Models\User;
use App\Waitlist\Exceptions\WaitlistException;
use App\Waitlist\Services\WaitlistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class StudentWaitlistController extends Controller
{
    public function index(): View
    {
        return view('student.waitlist.index');
    }

    public function store(User $instructor, WaitlistService $waitlist): RedirectResponse
    {
        try {
            $waitlist->join(auth()->user(), $instructor);
        } catch (WaitlistException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf("You've joined %s's waitlist.", $instructor->name));
    }

    public function destroy(User $instructor, WaitlistService $waitlist): RedirectResponse
    {
        $entry = InstructorWaitlistEntry::query()
            ->where('student_user_id', auth()->id())
            ->where('instructor_user_id', $instructor->id)
            ->where('status', WaitlistEntryStatus::Waiting->value)
            ->first();

        if ($entry !== null) {
            $waitlist->leave(auth()->user(), $entry);
        }

        return back()->with('success', 'You left the waitlist.');
    }
}
