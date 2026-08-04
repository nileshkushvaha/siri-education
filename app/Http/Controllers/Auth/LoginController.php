<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\LoginResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Auth\LoginService;
use App\Services\PortalResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function __construct(
        private readonly LoginService $loginService,
        private readonly PortalResolver $portal,
    ) {}

    public function showForm(Request $request): View|RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->intended($this->portal->loginRedirect(auth()->user()));
        }

        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        // Admin-portal roles (super_admin, manager) must authenticate through
        // /admin/login — one login door per portal. We check by email before
        // attempting credentials so no session is ever created here for them.
        $candidate = User::where('email', strtolower($request->input('email', '')))->first();

        if ($candidate && $this->portal->usesAdminPortal($candidate)) {
            return redirect(route('filament.admin.auth.login'))
                ->withInput($request->only('email'))
                ->with('error', 'Please sign in through the Administration Portal.');
        }

        $result = $this->loginService->attempt(
            email: $request->input('email'),
            password: $request->input('password'),
            remember: (bool) $request->input('remember', false),
            ipAddress: $request->ip() ?? '127.0.0.1',
            userAgent: $request->userAgent() ?? '',
            sessionId: $request->session()->getId(),
        );

        if ($result->isSuccessful()) {
            $request->session()->regenerate();

            return redirect()->intended($this->portal->loginRedirect(auth()->user()));
        }

        // Email unverified — the password was correct, so LoginService has
        // already issued a code and marked this session as allowed to
        // verify the account. Send them straight to the code screen.
        if ($result === LoginResult::EmailUnverified) {
            return redirect()->route('auth.verification.notice')
                ->with('success', 'Your email address is not verified yet. Enter the 6-digit code we just emailed you.');
        }

        $errorMessage = $result->message();

        // Append remaining-attempts hint when credentials were wrong
        if ($result === LoginResult::InvalidCredentials) {
            $remaining = session('login_remaining_attempts');

            if ($remaining !== null && $remaining > 0) {
                $word = $remaining === 1 ? 'attempt' : 'attempts';
                $errorMessage .= " {$remaining} {$word} remaining.";
            }
        }

        return back()
            ->withInput($request->only('email', 'remember'))
            ->withErrors(['email' => $errorMessage])
            ->with('login_result', $result->value);
    }
}
