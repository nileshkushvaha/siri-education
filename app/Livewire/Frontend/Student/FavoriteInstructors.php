<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Models\User;
use App\Services\Student\StudentFavoriteInstructorService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

final class FavoriteInstructors extends Component
{
    /** @var Collection<int, User> */
    public Collection $favorites;

    public function mount(StudentFavoriteInstructorService $favorites): void
    {
        $this->favorites = $favorites->bookableFavorites(auth()->user(), 12);
    }

    public function remove(int $instructorId, StudentFavoriteInstructorService $favorites): void
    {
        $instructor = User::findOrFail($instructorId);
        $favorites->unfavorite(auth()->user(), $instructor);
        $this->favorites = $favorites->bookableFavorites(auth()->user(), 12);
        session()->flash('success', 'Instructor removed from favorites.');
    }

    public function render(): View
    {
        return view('livewire.frontend.student.favorite-instructors');
    }
}
