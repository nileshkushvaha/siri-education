<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Auth\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Applies one of the app's existing named rate limiters (registered
 * once in AppServiceProvider::registerRateLimiters(), e.g. 'login',
 * 'password.reset') inside a Livewire component action.
 *
 * Why this exists: the `throttle:*` middleware only protects a page's
 * own route, but Livewire action calls (clicking "Sign in") are
 * dispatched to Livewire's own internal update endpoint, not the page
 * route — so route-level throttle middleware never runs for them.
 * This resolves the SAME named limiter definition Laravel's throttle
 * middleware would use and applies it manually, so the threshold and
 * on/off toggle (e.g. LoginSecuritySettings::throttling_enabled) are
 * defined in exactly one place, not duplicated here.
 */
trait ThrottlesLivewireRequests
{
    /**
     * @param  array<string, mixed>  $requestData  fields the limiter's
     *                                             resolver closure reads (e.g. ['email' => $this->email])
     *
     * @throws ValidationException when the limit has been exceeded
     */
    protected function throttleLimiter(string $limiterName, array $requestData, string $errorField): void
    {
        $request = Request::create('/', 'POST', $requestData);
        $request->server->set('REMOTE_ADDR', request()->ip());

        $resolver = app(\Illuminate\Cache\RateLimiter::class)->limiter($limiterName);

        if ($resolver === null) {
            return;
        }

        $result = $resolver($request);

        // A limiter closure returns either a single Limit or a list of
        // them (e.g. 'guest-booking-write' returns two) — casting a
        // single object to (array) would explode its public properties
        // into array values instead of wrapping it, so branch instead.
        $limits = array_filter(is_array($result) ? $result : [$result]);

        foreach ($limits as $limit) {
            if (RateLimiter::tooManyAttempts($limit->key, $limit->maxAttempts)) {
                $seconds = RateLimiter::availableIn($limit->key);

                throw ValidationException::withMessages([
                    $errorField => sprintf(
                        'Too many attempts. Please try again in %d second%s.',
                        $seconds,
                        $seconds === 1 ? '' : 's',
                    ),
                ]);
            }

            RateLimiter::hit($limit->key, $limit->decaySeconds);
        }
    }
}
