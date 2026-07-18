<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\InstructorStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the instructor teaching workspace (lessons, students, earnings,
 * analytics, etc.) behind InstructorStatus::publiclyVisible() — the same
 * "cleared review, not suspended/archived" set the public profile already
 * uses. An instructor outside that set is sent to their application
 * status page instead of the teaching workspace.
 */
final class EnsureInstructorWorkspaceAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user?->hasRole('instructor') && ! in_array($user->profile?->instructor_status, InstructorStatus::publiclyVisible(), true)) {
            return redirect()->route('dashboard.instructor.onboarding');
        }

        return $next($request);
    }
}
