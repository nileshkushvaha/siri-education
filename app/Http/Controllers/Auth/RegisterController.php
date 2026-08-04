<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\Auth\RegistrationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Auth\RegistrationService;
use App\Services\PortalResolver;
use App\Support\InstructorApplicationIntent;
use App\Support\PendingEmailVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class RegisterController extends Controller
{
    public function __construct(
        private readonly RegistrationService $registrationService,
        private readonly PortalResolver $portal,
    ) {}

    public function showForm(): View
    {
        // EnsureRegistrationEnabled middleware already redirects when disabled.
        InstructorApplicationIntent::captureFromRequest();

        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        try {
            $result = $this->registrationService->register(
                data: $request->validated(),
                ipAddress: $request->ip() ?? '',
                userAgent: $request->userAgent() ?? '',
            );
        } catch (RegistrationException $e) {
            return back()
                ->withInput($request->only('first_name', 'last_name', 'email', 'phone', 'phone_country_iso2', 'country_id', 'referral_code'))
                ->with('error', $e->getMessage());
        }

        // Pending admin approval — do NOT log the user in; they cannot access the app yet
        if ($result->requiresApproval) {
            return redirect()->route('auth.login')
                ->with('success', 'Your account has been created and is awaiting administrator approval. You will be notified by email.');
        }

        // Email was auto-verified — user is fully active, log them in immediately
        if ($result->autoVerified) {
            Auth::login($result->user);
            $request->session()->regenerate();

            if (InstructorApplicationIntent::consume()) {
                return redirect()->route('dashboard.instructor.onboarding')
                    ->with('success', 'Welcome to '.config('app.name').'! Continue your instructor application below.');
            }

            return redirect()->intended($this->portal->loginRedirect($result->user))
                ->with('success', 'Welcome to '.config('app.name').'! Your account is ready.');
        }

        // Normal flow: no session is created yet. The account is verified
        // by entering the emailed code, and only then signed in — see
        // PendingEmailVerification for why this session is allowed to
        // finish that verification.
        PendingEmailVerification::remember($result->user);

        return redirect()
            ->route('auth.verification.notice')
            ->with('success', 'Account created! Enter the 6-digit code we just emailed you.');
    }
}
