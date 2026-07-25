<?php

declare(strict_types=1);

namespace App\Country\Services;

use App\Booking\Services\MarketplaceCountryResolver;
use App\Models\Country;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * GAP-029 requirement #3 — the single place every country-governed
 * mutation resolves "which country applies here", so no domain service
 * re-derives its own country logic:
 *
 *  - authenticated student: their own billing/profile country;
 *  - instructor action: the instructor's own profile country;
 *  - guest: the existing marketplace/default-country resolver
 *    (MarketplaceCountryResolver) — never duplicated;
 *  - financial obligations: callers must resolve at the moment the
 *    obligation is created and treat the result as authoritative
 *    from then on (this resolver is stateless and always answers
 *    "now", it never reads back a previously snapshotted value).
 *
 * No IP geolocation is performed anywhere in this resolver.
 */
final class CountryResolver
{
    public function __construct(
        private readonly MarketplaceCountryResolver $marketplace,
    ) {}

    public function forStudent(User $student): ?Country
    {
        return $student->profile?->country;
    }

    public function forInstructor(User $instructor): ?Country
    {
        return $instructor->profile?->country;
    }

    public function forGuest(Request $request): ?Country
    {
        return $this->marketplace->resolve(null, $request)->country;
    }
}
