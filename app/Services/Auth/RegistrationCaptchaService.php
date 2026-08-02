<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Contracts\Session\Session;

final class RegistrationCaptchaService
{
    public const string REGISTRATION = 'registration';

    public const string LOGIN = 'login';

    public const string CONTACT = 'contact';

    /**
     * Keyed per context. Registration and login each hold their own challenge,
     * so a visitor with both pages open (or who refreshes one question) cannot
     * silently invalidate the other form's answer.
     */
    private const string SESSION_PREFIX = 'auth.captcha.';

    public function __construct(private readonly Session $session) {}

    public function issue(string $context = self::REGISTRATION): string
    {
        $left = random_int(2, 9);
        $right = random_int(1, 9);
        $this->session->put($this->key($context), (string) ($left + $right));

        return "{$left} + {$right}";
    }

    public function verify(mixed $answer, string $context = self::REGISTRATION): bool
    {
        $expected = $this->session->get($this->key($context));

        return is_string($expected) && hash_equals($expected, trim((string) $answer));
    }

    public function clear(string $context = self::REGISTRATION): void
    {
        $this->session->forget($this->key($context));
    }

    private function key(string $context): string
    {
        // Registration keeps its original key so existing sessions — and the
        // tests that seed it directly — keep working; only new contexts use
        // the prefixed form.
        return $context === self::REGISTRATION
            ? 'registration.captcha'
            : self::SESSION_PREFIX.$context;
    }
}
