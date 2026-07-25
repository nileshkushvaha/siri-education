<?php

declare(strict_types=1);

namespace App\Booking\Validation\Rules;

use App\Booking\Contracts\BookingRuleInterface;
use App\Booking\Contracts\BookingTypeInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Exceptions\FreeDemoUnavailableException;
use App\Booking\Types\FreeDemoType;
use App\Country\Enums\CountryFeature;
use App\Country\Services\CountryFeatureResolver;
use App\Country\Services\CountryResolver;
use App\Models\User;

/**
 * SRS-20-5 / GAP-026, extended by GAP-029 (SRS §20.36/§21.36) — the
 * platform-wide `demo_lessons_enabled` switch AND the requesting
 * student's country-level override both control whether NEW free-demo
 * bookings may be created; neither has any bearing on
 * `BookingType::is_active` (which controls whether the configured
 * booking type is operational at all) or on any already-created demo
 * booking's lifecycle. Registered first in FreeDemoType::rules() —
 * deliberately before OneFreeDemoPerInstructorRule — so a disabled
 * feature returns a generic "unavailable" response without ever
 * touching (or revealing) the student's prior demo-eligibility history
 * for this instructor. The key() guard is defense-in-depth in case
 * this rule is ever resolved for a different type by mistake — it must
 * never affect paid bookings.
 */
final class DemoLessonsEnabledRule implements BookingRuleInterface
{
    public function __construct(
        private readonly CountryFeatureResolver $countryFeatures,
        private readonly CountryResolver $countryResolver,
    ) {}

    public function check(CreateBookingData $data, BookingTypeInterface $type): void
    {
        if ($type->key() !== FreeDemoType::KEY) {
            return;
        }

        $student = User::query()->find($data->studentId);
        $country = $student !== null ? $this->countryResolver->forStudent($student) : null;

        if (! $this->countryFeatures->isEnabled(CountryFeature::DemoLessons, $country)) {
            throw FreeDemoUnavailableException::make();
        }
    }
}
