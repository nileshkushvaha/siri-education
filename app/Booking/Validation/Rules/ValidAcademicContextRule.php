<?php

declare(strict_types=1);

namespace App\Booking\Validation\Rules;

use App\Booking\Contracts\BookingRuleInterface;
use App\Booking\Contracts\BookingTypeInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Exceptions\BookingException;
use App\Booking\Types\FreeDemoType;
use App\Curriculum\Services\InstructorAcademicEligibilityResolver;
use App\Models\User;

/**
 * Phase 3 §29 — final defense-in-depth orchestration for a country-aware
 * Free Demo booking. `CreateBookingData::$academicContext` is only ever
 * non-null when WizardBookingService already resolved and validated it
 * via DemoAcademicContextResolver + AcademicContextResolver, so this
 * rule does NOT re-run that resolution (never duplicate the resolver's
 * own logic) — it only re-confirms the final selected instructor is
 * still academically eligible for that already-trusted context,
 * immediately before the booking is persisted, exactly like every other
 * BookingRuleInterface in this pipeline. A null academicContext means
 * either a legacy booking or the feature is disabled for this student's
 * country — nothing to validate here, the legacy free-text flow is
 * unaffected.
 */
final class ValidAcademicContextRule implements BookingRuleInterface
{
    public function __construct(
        private readonly InstructorAcademicEligibilityResolver $eligibility,
    ) {}

    public function check(CreateBookingData $data, BookingTypeInterface $type): void
    {
        if ($type->key() !== FreeDemoType::KEY || $data->academicContext === null) {
            return;
        }

        $instructor = User::find($data->instructorId);

        if ($instructor === null) {
            throw new BookingException('Selected instructor is not available.');
        }

        if (! $this->eligibility->isEligible($instructor, $data->academicContext->toAcademicContextData())) {
            throw new BookingException('This instructor is not eligible to teach the selected curriculum.');
        }
    }
}
