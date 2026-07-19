<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\BookingTypeRepositoryInterface;
use App\Booking\Types\FreeDemoType;
use App\Settings\FeatureSettings;

/**
 * Phase 24B.1 — the single "is a free demo currently available at the
 * platform level" answer (booking type active AND demo_lessons_enabled),
 * reused by every response that explicitly advertises demo availability
 * (instructor cards/profiles, teacher-lookup endpoints) so they can
 * never drift from each other.
 *
 * Deliberately NOT used by DemoLessonsEnabledRule (Phase 24B's
 * authoritative booking-creation rejection), which remains unchanged:
 * BookingService::request() already guarantees the type is active via
 * BookingTypeRepository::requireActiveByKey() before any rule runs, so
 * that rule only needs the global toggle. This resolver exists for read
 * paths that don't have that same guarantee.
 */
final class DemoAvailabilityResolver
{
    public function __construct(
        private readonly FeatureSettings $features,
        private readonly BookingTypeRepositoryInterface $types,
    ) {}

    public function isAvailable(): bool
    {
        if (! $this->features->demo_lessons_enabled) {
            return false;
        }

        return (bool) $this->types->findByKey(FreeDemoType::KEY)?->is_active;
    }
}
