<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Contracts\Session\Session;

final class RegistrationCaptchaService
{
    private const string SESSION_KEY = 'registration.captcha';

    public function __construct(private readonly Session $session) {}

    public function issue(): string
    {
        $left = random_int(2, 9);
        $right = random_int(1, 9);
        $this->session->put(self::SESSION_KEY, (string) ($left + $right));

        return "{$left} + {$right}";
    }

    public function verify(mixed $answer): bool
    {
        $expected = $this->session->get(self::SESSION_KEY);

        return is_string($expected) && hash_equals($expected, trim((string) $answer));
    }

    public function clear(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }
}
