<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\DTOs\MarketplaceCountryContext;
use App\Models\Country;
use App\Models\User;
use App\Settings\LocalizationSettings;
use Illuminate\Http\Request;

/**
 * The single authority on which country a marketplace pricing preview
 * localizes to (SRS §9.14). An authenticated student's own canonical
 * billing country is never overridden by a client-supplied value. A
 * guest's explicit choice — validated against active countries, never
 * trusted blindly — is remembered for the session; absent a choice,
 * the platform's own configured anonymous-preview default
 * (`LocalizationSettings::default_country`) is used and flagged as
 * such, never silently presented as the guest's own country. No IP
 * geolocation is performed — none exists elsewhere in this codebase.
 */
final class MarketplaceCountryResolver
{
    private const string SESSION_KEY = 'marketplace_pricing_country_iso2';

    private const string QUERY_KEY = 'pricing_country';

    public function __construct(
        private readonly LocalizationSettings $localization,
    ) {}

    public function resolve(?User $viewer, Request $request): MarketplaceCountryContext
    {
        if ($viewer !== null) {
            return new MarketplaceCountryContext($viewer->profile?->country, isGuestDefault: false, isAuthenticatedStudent: true);
        }

        $hasSession = $request->hasSession();
        $requested = $request->query(self::QUERY_KEY);

        if (is_string($requested) && $requested !== '') {
            $selected = $this->activeCountryByIso2($requested);

            if ($selected !== null) {
                if ($hasSession) {
                    $request->session()->put(self::SESSION_KEY, $selected->iso2);
                }

                return new MarketplaceCountryContext($selected, isGuestDefault: false, isAuthenticatedStudent: false);
            }
        }

        $sessionCode = $hasSession ? $request->session()->get(self::SESSION_KEY) : null;

        if (is_string($sessionCode)) {
            $selected = $this->activeCountryByIso2($sessionCode);

            if ($selected !== null) {
                return new MarketplaceCountryContext($selected, isGuestDefault: false, isAuthenticatedStudent: false);
            }
        }

        $default = $this->activeCountryByIso2($this->localization->default_country);

        return new MarketplaceCountryContext($default, isGuestDefault: true, isAuthenticatedStudent: false);
    }

    private function activeCountryByIso2(string $iso2): ?Country
    {
        return Country::query()->active()->where('iso2', strtoupper($iso2))->first();
    }
}
