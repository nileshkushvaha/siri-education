<?php

declare(strict_types=1);

namespace App\Rules;

use App\Settings\BookingSettings;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cloudflare Turnstile verification. A no-op while
 * BookingSettings::captcha_enabled is off, so unauthenticated flows
 * can toggle protection without code changes.
 */
final class TurnstileToken implements ValidationRule
{
    /** Run even when the field is absent — bots simply omit it. */
    public bool $implicit = true;

    private const string VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $settings = app(BookingSettings::class);

        if (! $settings->captcha_enabled) {
            return;
        }

        if (blank($value) || ! is_string($value)) {
            $fail('Please complete the security check.');

            return;
        }

        try {
            $verified = Http::asForm()
                ->timeout(5)
                ->post(self::VERIFY_URL, [
                    'secret' => (string) $settings->turnstile_secret_key,
                    'response' => $value,
                    'remoteip' => request()->ip(),
                ])
                ->json('success') === true;
        } catch (Throwable) {
            $verified = false;
        }

        if (! $verified) {
            $fail('Security check failed — please try again.');
        }
    }
}
