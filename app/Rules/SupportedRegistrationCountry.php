<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Country;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class SupportedRegistrationCountry implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $supported = Country::query()
            ->active()
            ->whereKey($value)
            ->whereHas('defaultCurrency', fn ($query) => $query->active())
            ->exists();

        if (! $supported) {
            $fail('Please select a supported country with an active billing currency.');
        }
    }
}
