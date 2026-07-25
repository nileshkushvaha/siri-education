<?php

declare(strict_types=1);

namespace App\Country\Support;

use App\Country\Enums\CountryFeature;
use Illuminate\Validation\ValidationException;

/**
 * Normalizes and validates a raw `Country::feature_flags` array before
 * it is persisted (SRS §21.38 "must reference a valid feature", §21.40
 * "Country Feature Dependency Missing"). Called once, from
 * `Country::booted()`, so every write path — Filament, tinker,
 * factories, seeders — is covered the same way. Throws the same
 * `ValidationException` idiom `WalletService::resolveCurrency()`
 * already uses for an unrecognized key, so a Filament/Livewire save
 * surfaces it as an inline field error rather than a raw 500.
 *
 * A dependent whose prerequisite is disabled is silently normalized to
 * disabled too, rather than rejecting the save: the admin form's
 * toggles all dehydrate on every save (every feature, not just the one
 * the admin touched), so a strict reject-on-inconsistency rule would
 * misfire whenever ANY single feature with dependents is turned off —
 * every other untouched, default-true toggle would look like an
 * "attempt to enable" it. `CountryFeatureResolver::isEnabled()` already
 * makes this composition authoritative at read time regardless of what
 * is stored; this normalization only keeps the persisted data from
 * looking misleadingly inconsistent on the next page load.
 */
final class CountryFeatureFlags
{
    /**
     * @param  array<mixed, mixed>  $raw
     * @return array<string, bool>
     *
     * @throws ValidationException
     */
    public static function validate(array $raw): array
    {
        $normalized = [];

        foreach ($raw as $key => $value) {
            $feature = CountryFeature::tryFrom((string) $key);

            if ($feature === null) {
                throw ValidationException::withMessages([
                    'feature_flags' => [sprintf('"%s" is not a recognized platform feature.', $key)],
                ]);
            }

            $normalized[$feature->value] = (bool) $value;
        }

        foreach ($normalized as $key => $enabled) {
            if (! $enabled) {
                continue;
            }

            foreach (CountryFeature::from($key)->dependencies() as $dependency) {
                if (($normalized[$dependency->value] ?? true) === false) {
                    $normalized[$key] = false;

                    break;
                }
            }
        }

        return $normalized;
    }
}
