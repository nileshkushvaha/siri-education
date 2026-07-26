<?php

declare(strict_types=1);

namespace App\Booking\Validation\Rules;

use App\Booking\Contracts\BookingRuleInterface;
use App\Booking\Contracts\BookingTypeInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Exceptions\BookingException;
use App\Booking\Types\PaidOneToOneType;
use App\Country\Enums\CountryFeature;
use App\Country\Services\CountryFeatureResolver;
use App\Country\Services\CountryResolver;
use App\Models\User;

/**
 * SRS §20.36/§21.36 ("Country-Level Feature Flags") — a country may
 * disable new paid bookings even while
 * `PaymentGatewaySettings::payments_enabled` stays on platform-wide.
 * This is independent of (and composes with, never replaces) the
 * existing payment-provider country routing in
 * `PaymentCollectionEligibilityService`/`PaymentProviderResolver` —
 * that decides which provider handles a country's payments; this rule
 * decides whether a NEW paid booking may be created for the country at
 * all. The key() guard mirrors DemoLessonsEnabledRule's and must never
 * affect free-demo bookings.
 */
final class PaidBookingsCountryEnabledRule implements BookingRuleInterface
{
    public function __construct(
        private readonly CountryFeatureResolver $countryFeatures,
        private readonly CountryResolver $countryResolver,
    ) {}

    public function check(CreateBookingData $data, BookingTypeInterface $type): void
    {
        if ($type->key() !== PaidOneToOneType::KEY) {
            return;
        }

        $student = User::query()->find($data->studentId);
        $country = $student !== null ? $this->countryResolver->forStudent($student) : null;

        if (! $this->countryFeatures->isEnabled(CountryFeature::PaidBookings, $country)) {
            throw new BookingException('Paid bookings are not currently available for your country.');
        }
    }
}
