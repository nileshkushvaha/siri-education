<?php

declare(strict_types=1);

namespace App\Package\Services;

use App\Booking\Exceptions\BookingException;
use App\Booking\Services\StudentLessonPriceResolver;
use App\Country\Services\CountryResolver;
use App\Models\AcademicLevel;
use App\Models\BookingType;
use App\Models\Subject;
use App\Models\User;
use App\Package\DTOs\ResolvedPackagePriceData;
use App\Package\Exceptions\PackageException;

/**
 * Thin composition over the existing pricing/country infrastructure —
 * the only new math anywhere in the Package domain is
 * `calculated_price_minor = unit_price_minor * paid_quantity`. Never
 * duplicates StudentLessonPriceResolver's match/tiering logic and
 * never introduces a second "resolved student currency" concept —
 * the currency locked onto a proposal is always the resolved
 * StudentLessonPrice row's own currency (see docs/architecture/
 * phase-10.2d-student-pricing-matrix.md).
 */
final class PackagePricingService
{
    public function __construct(
        private readonly CountryResolver $countryResolver,
        private readonly StudentLessonPriceResolver $priceResolver,
    ) {}

    /** @throws PackageException when the student has no resolvable billing country, or no matching price is configured */
    public function resolve(
        User $student,
        User $instructor,
        BookingType $type,
        Subject $subject,
        ?AcademicLevel $academicLevel,
        int $durationMinutes,
        int $paidQuantity,
    ): ResolvedPackagePriceData {
        $country = $this->countryResolver->forStudent($student);

        if ($country === null || $country->status !== 'active') {
            throw new PackageException('The student has no active billing country on their profile yet.');
        }

        try {
            $price = $this->priceResolver->resolve($type, $subject, $academicLevel, $durationMinutes, $country, $instructor->id);
        } catch (BookingException $e) {
            throw new PackageException($e->getMessage());
        }

        return new ResolvedPackagePriceData(
            countryId: $country->id,
            currencyId: $price->currency_id,
            currencyCode: $price->currency_code,
            unitPriceMinor: $price->amount_minor,
            paidQuantity: $paidQuantity,
            calculatedPriceMinor: $price->amount_minor * $paidQuantity,
        );
    }
}
