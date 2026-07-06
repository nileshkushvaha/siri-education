<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Student\StudentFavoriteInstructorService;
use Illuminate\Http\RedirectResponse;

final class StudentFavoriteInstructorController extends Controller
{
    public function store(User $instructor, StudentFavoriteInstructorService $favorites): RedirectResponse
    {
        $favorites->favorite(auth()->user(), $instructor);

        return back()->with('success', 'Instructor added to favorites.');
    }

    public function destroy(User $instructor, StudentFavoriteInstructorService $favorites): RedirectResponse
    {
        $favorites->unfavorite(auth()->user(), $instructor);

        return back()->with('success', 'Instructor removed from favorites.');
    }
}
