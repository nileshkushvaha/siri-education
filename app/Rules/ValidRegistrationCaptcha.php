<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Auth\RegistrationCaptchaService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidRegistrationCaptcha implements ValidationRule
{
    public function __construct(private readonly RegistrationCaptchaService $captcha) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->captcha->verify($value)) {
            $fail('Please solve the security question correctly.');
        }
    }
}
