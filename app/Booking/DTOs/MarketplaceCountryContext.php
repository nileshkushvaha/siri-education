<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Models\Country;

/**
 * The country a marketplace pricing preview should localize to for the
 * current viewer. `isGuestDefault` distinguishes "the platform's
 * configured anonymous-preview default" from "a guest explicitly chose
 * this" — the UI must label the former ("Prices for India") and offer
 * a way to change it, never present it as the guest's own billing
 * country.
 */
final readonly class MarketplaceCountryContext
{
    public function __construct(
        public ?Country $country,
        public bool $isGuestDefault,
        public bool $isAuthenticatedStudent,
    ) {}
}
