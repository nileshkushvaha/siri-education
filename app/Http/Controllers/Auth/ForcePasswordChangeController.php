<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForcePasswordChangeRequest;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Services\AuditTrailService;
use App\Services\Auth\PasswordHistoryService;
use App\Services\Auth\PasswordLifecycleService;
use App\Services\PortalResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class ForcePasswordChangeController extends Controller
{
    public function __construct(
        private readonly PasswordLifecycleService $lifecycle,
        private readonly PasswordHistoryService $historyService,
        private readonly PortalResolver $portal,
        private readonly AuditTrailService $audit,
    ) {}

    public function showForm(): View|RedirectResponse
    {
        $user = auth()->user();

        if (! $this->lifecycle->mustChange($user)) {
            return redirect()->intended($this->portal->loginRedirect(auth()->user()));
        }

        return view('auth.force-password-change', [
            'googleActivation' => $this->lifecycle->awaitingActivationPassword($user),
        ]);
    }

    public function store(ForcePasswordChangeRequest $request): RedirectResponse
    {
        $user = $request->user();
        $oldHash = $user->password;
        $googleActivation = $this->lifecycle->awaitingActivationPassword($user);

        $this->historyService->assertNotReused($user, $request->validated('password'));

        $user->forceFill([
            'password' => Hash::make($request->validated('password')),
            'password_changed_at' => now(),
            'must_change_password' => false,
        ])->save();

        $this->historyService->store($user, $oldHash);

        // Regenerate session to bind the updated credentials
        $request->session()->regenerate();

        activity('auth')
            ->causedBy($user)
            ->performedOn($user)
            ->event('password_changed')
            ->withProperties(['ip' => $request->ip(), 'reason' => $googleActivation ? 'google_activation' : 'forced_first_login'])
            ->log('Password changed on first login');

        if ($googleActivation) {
            $this->audit->logUser($user, 'auth', 'account_activated', 'Account activated: password created after Google identity verification', $user, [
                'login_method' => 'google',
            ]);
        }

        $user->notify(new PasswordChangedNotification(
            ipAddress: $request->ip() ?? '127.0.0.1',
            changedAt: Carbon::now()->toDateTimeString(),
        ));

        return redirect()->intended($this->portal->loginRedirect($user))
            ->with('success', $googleActivation ? 'Your password is set and your account is activated. Welcome!' : 'Password updated successfully. Welcome!');
    }
}
