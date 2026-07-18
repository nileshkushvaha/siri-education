<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\Auth\RegistrationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Auth\RegistrationService;
use App\Services\PortalResolver;
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

            return redirect()->intended($this->portal->loginRedirect($result->user))
                ->with('success', 'Welcome to '.config('app.name').'! Your account is ready.');
        }

        // Normal flow: log in temporarily so the signed verification URL works,
        // then redirect to the verification notice.
        Auth::login($result->user);

        return redirect()
            ->route('auth.verification.notice')
            ->with('success', 'Account created! Please check your email to verify your address before signing in.');
    }
}
