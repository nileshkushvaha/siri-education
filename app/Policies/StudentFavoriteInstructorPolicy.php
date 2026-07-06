<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StudentFavoriteInstructor;
use App\Models\User;

class StudentFavoriteInstructorPolicy
{
    public function view(User $user, StudentFavoriteInstructor $favorite): bool
    {
        return $favorite->student_user_id === $user->id || $user->can('View:StudentFavoriteInstructor');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('student') || $user->can('Create:StudentFavoriteInstructor');
    }

    public function delete(User $user, StudentFavoriteInstructor $favorite): bool
    {
        return $favorite->student_user_id === $user->id || $user->can('Delete:StudentFavoriteInstructor');
    }
}
