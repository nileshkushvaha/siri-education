<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Student\StudentProfileCompletenessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Booking precondition: a student must have completed the basics
 * (country, mobile number, terms) before entering the booking flow.
 * Sends them to the complete-profile screen and remembers where they
 * were going. Non-students pass through untouched.
 */
class EnsureStudentProfileComplete
{
    public function __construct(private readonly StudentProfileCompletenessService $completeness) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $this->completeness->isComplete($user)) {
            if ($request->expectsJson()) {
                abort(403, 'Please complete your profile before booking.');
            }

            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('account.complete-profile')
                ->with('info', 'Almost there! Add your country, mobile number and accept the terms to start booking.');
        }

        return $next($request);
    }
}
