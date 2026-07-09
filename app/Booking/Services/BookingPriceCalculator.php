<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\DTOs\BookingPriceData;
use App\Booking\Exceptions\BookingException;
use App\Models\AcademicLevel;
use App\Models\BookingType;
use App\Models\StudentLessonPrice;
use App\Models\Subject;
use App\Models\User;
use App\Settings\GeneralSettings;

/**
 * Single source of truth for what a booking costs, ahead of any
 * checkout/payment integration. No discount or tax system exists yet,
 * so those are always zero — modeled now so a future discount/tax
 * engine is additive, not a breaking change to every caller.
 *
 * Phase 10.2D: the student-facing price for a paid lesson is resolved
 * exclusively through {@see StudentLessonPriceResolver} (the
 * admin-managed pricing matrix — subject + academic level + country +
 * duration). `BookingType` defines booking *behavior* only (duration,
 * capacity, approval, paid-or-not) — it has never owned the
 * student-facing price since this phase.
 *
 * Phase 10.2D-Cleanup: the earlier `booking_types.price`/`currency`
 * fallback is removed — those columns no longer exist on the table.
 * A paid booking with no matching active `StudentLessonPrice` row is a
 * configuration error, never a free booking, exactly like Phase
 * 10.2C-Fix's original guard, now enforced by the matrix being the
 * only source instead of by a decimal column that could be silently
 * left null.
 */
final class BookingPriceCalculator
{
    public function __construct(
        private readonly GeneralSettings $settings,
        private readonly StudentLessonPriceResolver $lessonPrices,
    ) {}

    /**
     * @param  string|null  $subjectSlug  matched against Subject.slug/name —
     *                                    best-effort only; TeacherSubject.subject
     *                                    stays free-text, this does not require
     *                                    every booking to have a linked Subject
     * @param  int|null  $grade  raw grade (1-12), matched against AcademicLevel::coversGrade()
     * @param  int|null  $instructorId  the booking's host/teacher — when a
     *                                  StudentLessonPrice row exists for this
     *                                  exact instructor (Phase 10.2F), it
     *                                  overrides the base price; otherwise
     *                                  the base (instructor-less) price is
     *                                  used, unchanged from before
     *
     * @throws BookingException when a paid type has no active matrix price configured
     */
    public function calculate(BookingType $type, ?User $student = null, ?string $subjectSlug = null, ?int $grade = null, ?int $instructorId = null): BookingPriceData
    {
        if (! $type->is_paid) {
            return new BookingPriceData(
                baseAmount: 0.0,
                discountAmount: 0.0,
                taxAmount: 0.0,
                payableAmount: 0.0,
                currency: $this->studentCurrency($student) ?? $this->settings->default_currency,
                requiresPayment: false,
                isFreeBooking: true,
            );
        }

        $matrixPrice = $this->resolveFromMatrix($type, $student, $subjectSlug, $grade, $instructorId);
        $baseAmount = $matrixPrice->amountDecimal();

        return new BookingPriceData(
            baseAmount: $baseAmount,
            discountAmount: 0.0,
            taxAmount: 0.0,
            payableAmount: max(0.0, $baseAmount),
            currency: $matrixPrice->currency_code,
            requiresPayment: true,
            isFreeBooking: false,
        );
    }

    /** @throws BookingException when there isn't enough context to match, or nothing matches */
    private function resolveFromMatrix(BookingType $type, ?User $student, ?string $subjectSlug, ?int $grade, ?int $instructorId): StudentLessonPrice
    {
        $notConfigured = fn (): BookingException => new BookingException(sprintf(
            'The "%s" lesson price is not configured yet. Please contact support.',
            $type->name,
        ));

        if ($subjectSlug === null || $grade === null) {
            throw $notConfigured();
        }

        $country = $student?->profile?->country;

        if ($country === null) {
            throw $notConfigured();
        }

        $subject = Subject::query()->active()
            ->where(fn ($q) => $q->where('slug', $subjectSlug)->orWhere('name', $subjectSlug))
            ->first();

        if ($subject === null) {
            throw $notConfigured();
        }

        $academicLevel = AcademicLevel::query()->active()->get()
            ->first(fn (AcademicLevel $level): bool => $level->coversGrade($grade));

        return $this->lessonPrices->resolve($type, $subject, $academicLevel, $type->duration_minutes, $country, $instructorId);
    }

    /** The student's country default currency — display fallback only, never a conversion. */
    private function studentCurrency(?User $student): ?string
    {
        return $student?->profile?->country?->defaultCurrency?->code;
    }
}
