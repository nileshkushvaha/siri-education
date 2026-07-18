<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\Instructor\InstructorEligibilityResult;
use App\Models\User;

/**
 * Single authority for "is this user allowed to start an instructor
 * application?". No controller, Livewire component, or other service
 * may duplicate these rules — call evaluate() instead.
 */
interface InstructorEligibilityServiceInterface
{
    public function evaluate(User $user): InstructorEligibilityResult;
}
