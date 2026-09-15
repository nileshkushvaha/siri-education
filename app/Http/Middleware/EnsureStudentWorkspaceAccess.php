<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the student workspace (booking, lessons, homework, wallet,
 * payments, …) for accounts that were never students. The student role
 * is only ever granted at registration; an account registered to teach
 * holds `instructor` alone and can never gain `student` later — so
 * instead of a bare 403 or a generic "not available" error, such an
 * account is shown what it is and what to do (register a separate
 * learning account). Dual-role users (a student who later applied to
 * teach) still hold `student` and pass straight through.
 *
 * This is the explanation layer only: every student action is still
 * enforced server-side by StudentLifecycleService::assertEligibleForStudentAction().
 */
final class EnsureStudentWorkspaceAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null && $user->hasRole('instructor') && ! $user->hasRole('student')) {
            if ($request->expectsJson()) {
                abort(403, 'This is an instructor account. Learning features are only available on a student account.');
            }

            return response()->view('dashboard.student-access-unavailable', [], 403);
        }

        return $next($request);
    }
}
