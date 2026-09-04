<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\DTOs\BookingPriceData;
use App\Booking\Exceptions\BookingException;
use App\Models\AcademicLevel;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\StudentLessonPrice;
use App\Models\Subject;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Support\Financial\CurrencyEligibilityPolicy;
use App\Support\Financial\Exceptions\CurrencyNotUsableException;
use App\Support\Financial\FinancialOperation;

/**
 * Single source of truth for what a booking costs, ahead of any
 * checkout/payment integration. No discount or tax system exists yet,
 * so those are always zero — modeled now so a future discount/tax
 * engine is additive, not a breaking change to every caller.
 *
 * The student-facing price for a paid lesson is resolved exclusively
 * through {@see StudentLessonPriceResolver} (the admin-managed pricing
 * matrix — subject + academic level + country + duration).
 * `BookingType` defines booking *behavior* only (duration, capacity,
 * approval, paid-or-not) — it never owns the student-facing price.
 *
 * There is no `booking_types.price`/`currency` fallback — those
 * columns do not exist on the table. A paid booking with no matching
 * active `StudentLessonPrice` row is a configuration error, never a
 * free booking; the matrix is the only price source.
 */
final class BookingPriceCalculator
{
    public function __construct(
        private readonly GeneralSettings $settings,
        private readonly StudentLessonPriceResolver $lessonPrices,
        private readonly CurrencyEligibilityPolicy $currencyEligibility,
    ) {}

    /**
     * @param  string|null  $subjectSlug  matched against Subject.slug/name —
     *                                    best-effort only; TeacherSubject.subject
     *                                    stays free-text, this does not require
     *                                    every booking to have a linked Subject
     * @param  int|null  $grade  raw grade (1-12), matched against AcademicLevel::coversGrade()
     * @param  int|null  $instructorId  the booking's host/teacher — when a
     *                                  StudentLessonPrice row exists for
     *                                  this exact instructor, it overrides
     *                                  the base price; otherwise the base
     *                                  (instructor-less) price is used
     * @param  string|null  $academicLevelId  the AcademicLevel the student
     *                                        actually selected (from the
     *                                        booking's academic context);
     *                                        tried first, ahead of any level
     *                                        merely covering the grade
     *
     * @throws BookingException when a paid type has no active matrix price configured
     */
    public function calculate(BookingType $type, ?User $student = null, ?string $subjectSlug = null, ?int $grade = null, ?int $instructorId = null, ?string $academicLevelId = null): BookingPriceData
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

        $matrixPrice = $this->resolveFromMatrix($type, $student, $subjectSlug, $grade, $instructorId, $academicLevelId);
        $baseAmount = $matrixPrice->amountDecimal();

        // A paid booking must never be created in a currency that can no
        // longer collect new payment — it could never be paid. Re-checked
        // again at BookingPaymentService::initiate() for the
        // stale-page/currency-disabled-after-booking-creation case.
        try {
            $this->currencyEligibility->assertUsable($matrixPrice->currency_code, FinancialOperation::NewInitiation);
        } catch (CurrencyNotUsableException $e) {
            throw new BookingException($e->getMessage());
        }

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
    private function resolveFromMatrix(BookingType $type, ?User $student, ?string $subjectSlug, ?int $grade, ?int $instructorId, ?string $academicLevelId = null): StudentLessonPrice
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

        return $this->lessonPrices->resolveForLevels(
            $type,
            $subject,
            $this->candidateLevels($grade, $country, $academicLevelId),
            $type->duration_minutes,
            $country,
            $instructorId,
        );
    }

    /**
     * Every academic level the price row could legitimately be keyed on,
     * most specific first:
     *
     *   1. the level the student actually selected in the booking flow
     *   2. the student's country's own levels (and global ones) covering the grade
     *   3. any other active level covering the grade
     *
     * This used to stop at the FIRST active level whose grade range
     * covered the grade, in table order. With per-country levels (US
     * "Grade 10", India "Class 10") and band levels ("Secondary 6-10")
     * several rows cover the same grade, so a price configured on the
     * correct level was missed whenever a different one sorted first —
     * surfacing to the student as "price is not configured".
     *
     * @return list<AcademicLevel>
     */
    private function candidateLevels(int $grade, Country $country, ?string $academicLevelId): array
    {
        $covering = AcademicLevel::query()->active()
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->filter(fn (AcademicLevel $level): bool => $level->coversGrade($grade));

        $selected = $academicLevelId !== null
            ? AcademicLevel::query()->active()->whereKey($academicLevelId)->get()
            : collect();

        $ownCountry = $covering->filter(fn (AcademicLevel $level): bool => $level->country_id === null || (int) $level->country_id === (int) $country->id);
        $elsewhere = $covering->reject(fn (AcademicLevel $level): bool => $ownCountry->contains('id', $level->id));

        return $selected->concat($ownCountry)->concat($elsewhere)->unique('id')->values()->all();
    }

    /** The student's country default currency — display fallback only, never a conversion. */
    private function studentCurrency(?User $student): ?string
    {
        return $student?->profile?->country?->defaultCurrency?->code;
    }
}
