<?php

declare(strict_types=1);

namespace App\Booking\Validation\Rules;

use App\Booking\Contracts\BookingRuleInterface;
use App\Booking\Contracts\BookingTypeInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Exceptions\FreeDemoUnavailableException;
use App\Booking\Types\FreeDemoType;
use App\Settings\FeatureSettings;

/**
 * SRS-20-5 / GAP-026 — the platform-wide `FeatureSettings::demo_lessons_enabled`
 * switch controls whether NEW free-demo bookings may be created; it has
 * no bearing on `BookingType::is_active` (which controls whether the
 * configured booking type is operational at all) or on any
 * already-created demo booking's lifecycle. Registered first in
 * FreeDemoType::rules() — deliberately before OneFreeDemoPerInstructorRule
 * — so a globally-disabled feature returns a generic "unavailable"
 * response without ever touching (or revealing) the student's prior
 * demo-eligibility history for this instructor. The key() guard is
 * defense-in-depth in case this rule is ever resolved for a different
 * type by mistake — it must never affect paid bookings.
 */
final class DemoLessonsEnabledRule implements BookingRuleInterface
{
    public function __construct(
        private readonly FeatureSettings $features,
    ) {}

    public function check(CreateBookingData $data, BookingTypeInterface $type): void
    {
        if ($type->key() !== FreeDemoType::KEY) {
            return;
        }

        if (! $this->features->demo_lessons_enabled) {
            throw FreeDemoUnavailableException::make();
        }
    }
}
