<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Services\Instructor\InstructorOnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

final class InstructorOnboardingController extends Controller
{
    public function __construct(
        private readonly InstructorOnboardingService $onboarding,
    ) {}

    public function start(): RedirectResponse
    {
        $this->onboarding->start(auth()->user());

        return redirect()
            ->route('profile.show', ['tab' => 'general'])
            ->with('success', 'Instructor onboarding started. Complete your professional profile to submit for review.');
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
