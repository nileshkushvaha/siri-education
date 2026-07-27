<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\LoginHistory;
use App\Models\User;
use App\Models\UserSession;
use App\Services\AuditTrailService;
use App\Settings\SessionSettings;
use App\Support\UserAgentParser;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * SRS-1-23: the single place that both tracks and
 * enforces per-session activity. Extends the pre-existing tracker
 * rather than adding a second one — UserSession (keyed by the real
 * Laravel session_id) is already exactly the "per-session, server-
 * written, survives between requests, distinguishes devices" activity
 * record this needs.
 *
 * Order within a request: the idle check runs BEFORE $next($request),
 * reading whatever last_activity_at was left by the PREVIOUS request —
 * never the value this request is about to write. Only after the
 * request is accepted (the check passed, or there was nothing to
 * check) does the existing post-request update refresh the timestamp.
 * Reversing this order would let a request refresh activity before its
 * own expiry could ever be judged, silently defeating the timeout.
 */
final class TrackUserSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && $request->hasSession()) {
            $expired = $this->expireIfIdle($request, $request->session()->getId());

            if ($expired) {
                return $this->handleExpiry($request);
            }
        }

        $response = $next($request);

        if ($request->user() && $request->hasSession()) {
            $sessionId = $request->session()->getId();
            $ua = $request->userAgent() ?? '';
            $parsed = UserAgentParser::parse($ua);

            UserSession::updateOrCreate(
                ['session_id' => $sessionId],
                [
                    'user_id' => $request->user()->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => substr($ua, 0, 500),
                    'browser' => $parsed['browser'],
                    'platform' => $parsed['platform'],
                    'device_type' => $parsed['device_type'],
                    'last_activity_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        return $response;
    }

    /**
     * Reads the CURRENT session's prior activity and, if the configured
     * idle window has elapsed, deletes the tracked row atomically under
     * a row lock and returns true. The lock spans read-decide-delete for
     * this one session_id, so a concurrent request for the SAME session
     * cannot read a stale timestamp and revive it: whichever request's
     * transaction commits first is authoritative, and the second sees
     * the fresh (already-deleted, or already-expired-checked) state.
     * Unrelated sessions are never locked against each other.
     *
     * Public so a console command or concurrency test can drive the
     * exact same locked check directly, without a full HTTP request.
     */
    public function expireIfIdle(Request $request, string $sessionId): bool
    {
        $timeoutMinutes = max(0, app(SessionSettings::class)->idle_timeout);

        if ($timeoutMinutes === 0) {
            return false;
        }

        return DB::transaction(function () use ($request, $sessionId, $timeoutMinutes): bool {
            $tracked = UserSession::query()
                ->where('session_id', $sessionId)
                ->lockForUpdate()
                ->first();

            if ($tracked === null) {
                return false;
            }

            $expiresAt = CarbonImmutable::instance($tracked->last_activity_at)->addMinutes($timeoutMinutes);

            if (CarbonImmutable::now()->lessThan($expiresAt)) {
                return false;
            }

            $this->recordExpiry($request, $tracked);

            $tracked->delete();

            return true;
        });
    }

    private function recordExpiry(Request $request, UserSession $tracked): void
    {
        /** @var User $user */
        $user = $request->user();

        LoginHistory::query()
            ->where('user_id', $user->id)
            ->where('session_id', $tracked->session_id)
            ->whereNull('logged_out_at')
            ->latest('logged_in_at')
            ->first()
            ?->update(['logged_out_at' => now()]);

        app(AuditTrailService::class)->logSystem(
            'auth',
            'session_expired',
            'Session expired due to inactivity',
            $user,
            [
                'reason' => 'inactivity',
                'last_activity_at' => $tracked->last_activity_at->toIso8601String(),
                'expired_at' => now()->toIso8601String(),
                'ip_address' => $tracked->ip_address,
                'device_type' => $tracked->device_type,
            ],
        );
    }

    /**
     * Mirrors EnsureAccountIsActive's established teardown mechanics
     * (logout the guard, invalidate the session, regenerate the CSRF
     * token — SessionGuard::logout() also clears this browser's
     * remember-me recaller, so the expired login cannot silently
     * recreate itself). The redirect target itself is resolved by
     * Laravel's own AuthenticationException handling, reusing the exact
     * admin-vs-frontend callback already registered via
     * $middleware->redirectGuestsTo() in bootstrap/app.php — this
     * correctly reaches both the frontend login and the Filament admin
     * login without duplicating that branch here, and returns a 401 JSON
     * response instead of a redirect for any request expecting JSON
     * (Livewire's update endpoint included).
     */
    private function handleExpiry(Request $request): never
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->flash('error', 'Your session expired due to inactivity. Please sign in again.');
        $request->session()->regenerateToken();

        throw new AuthenticationException('Your session expired due to inactivity. Please sign in again.');
    }
}
