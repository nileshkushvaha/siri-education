<?php

declare(strict_types=1);

namespace App\Booking\Support;

/**
 * Phase 4D — the student-facing wording for one academic flow.
 *
 * BookingAcademicContextResolver is deliberately booking-type-neutral:
 * it validates the same chain for a Free Demo, a paid lesson and a
 * package proposal. Only the sentence shown to the person differs
 * ("before booking a demo lesson" vs "before creating a package"), so
 * the copy travels as data rather than forcing a subclass or a
 * conditional per booking type inside the resolver.
 *
 * forDemo() reproduces Phase 3's existing demo strings VERBATIM — the
 * demo flow's behavior, including its exact messages, must not change
 * as a side effect of this extraction.
 */
final readonly class AcademicFlowCopy
{
    public function __construct(
        public string $missingCountry,
        public string $incompleteSelection,
        public string $staleSelection,
        public string $unsupportedLevel,
        public string $unavailableCurriculum,
    ) {}

    public static function forDemo(): self
    {
        return new self(
            missingCountry: 'Please complete your profile country before booking a demo lesson.',
            incompleteSelection: 'Please complete your education system, level, subject, and curriculum selections before booking a demo lesson.',
            staleSelection: 'One or more of your academic selections is no longer available. Please choose again.',
            unsupportedLevel: 'This level is not currently supported for demo booking. Please select a different level.',
            unavailableCurriculum: 'The selected curriculum is no longer available for booking.',
        );
    }

    /** Instructor-facing: the "student" in these sentences is the proposal's student, not the reader. */
    public static function forPackage(): self
    {
        return new self(
            missingCountry: 'This student has no active country on their profile yet, so a package cannot be created for them.',
            incompleteSelection: 'Please complete the education system, level, and subject selections before submitting this package.',
            staleSelection: 'One or more of the selected academic options is no longer available. Please choose again.',
            unsupportedLevel: 'This level is not currently supported for packages. Please select a different level.',
            unavailableCurriculum: 'The resolved curriculum is no longer available for this package.',
        );
    }

    /** Student-facing, for a package-funded paid booking. */
    public static function forPackageBooking(): self
    {
        return new self(
            missingCountry: 'Please complete your profile country before booking a lesson.',
            incompleteSelection: 'Please complete your education system, level, and subject selections before booking a lesson.',
            staleSelection: 'One or more of your academic selections is no longer available. Please choose again.',
            unsupportedLevel: 'This level is not currently supported for booking. Please select a different level.',
            unavailableCurriculum: 'The selected curriculum is no longer available for booking.',
        );
    }
}
