<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Services\Instructor\InstructorOnboardingService;
use App\Support\InstructorApplicationStart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class InstructorOnboardingController extends Controller
{
    public function __construct(
        private readonly InstructorOnboardingService $onboarding,
    ) {}

    public function show(): View
    {
        return view('instructor.onboarding');
    }

    public function start(): RedirectResponse
    {
        $user = auth()->user();
        $eligibility = InstructorApplicationStart::attempt($user, 'dashboard');

        if (! $eligibility->eligible) {
            return redirect()
                ->route('dashboard.instructor.onboarding')
                ->with('error', $eligibility->reason);
        }

        $this->onboarding->start($user);

        return redirect()
            ->route('dashboard.instructor.onboarding')
            ->with('success', 'Instructor onboarding started.');
    }

    public function submit(): RedirectResponse
    {
        try {
            $this->onboarding->submit(auth()->user());
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors())
                ->with('active_tab', 'general');
        }

        return back()->with('success', 'Instructor application submitted for review.');
    }
}
