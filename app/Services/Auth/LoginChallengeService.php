<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Decides when the login form should present a security question.
 *
 * A captcha on every sign-in taxes the overwhelming majority of visitors who
 * are simply logging in, to slow down the few who are not. So the challenge is
 * held back until there is an actual signal: repeated failed credentials from
 * the same origin.
 *
 * Counted per IP rather than per account on purpose — credential stuffing
 * sprays many addresses from one origin, so an account-scoped counter would
 * never trip. It also means an attacker cannot lock a victim's login form into
 * a permanent challenge by failing against their email.
 *
 * This is a friction control, not an authorization boundary: the real
 * protections (account lockout, the named login rate limiter, failed-attempt
 * tracking) are unchanged and still do the enforcing.
 */
final class LoginChallengeService
{
    /** Failed credential attempts from one origin before a question appears. */
    public const int THRESHOLD = 3;

    /** How long failures are remembered; also how long the challenge persists. */
    public const int DECAY_SECONDS = 900;

    public function requiresChallenge(?string $ipAddress): bool
    {
        return RateLimiter::attempts($this->key($ipAddress)) >= self::THRESHOLD;
    }

    /** Called only for genuine credential failures, never for status rejections. */
    public function recordFailure(?string $ipAddress): void
    {
        RateLimiter::hit($this->key($ipAddress), self::DECAY_SECONDS);
    }

    public function clear(?string $ipAddress): void
    {
        RateLimiter::clear($this->key($ipAddress));
    }

    private function key(?string $ipAddress): string
    {
        return 'login-challenge:'.($ipAddress !== null && $ipAddress !== '' ? $ipAddress : 'unknown');
    }
}
