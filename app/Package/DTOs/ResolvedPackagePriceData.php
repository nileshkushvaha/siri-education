<?php

declare(strict_types=1);

namespace App\Package\DTOs;

/**
 * The output of PackagePricingService::resolve() — a fully-resolved
 * per-lesson price (from the existing StudentLessonPriceResolver,
 * never duplicated logic) plus the derived package calculation
 * (unit_price_minor * paid_quantity). No admin override travels on
 * this DTO — that only ever exists as a separate, explicit admin
 * decision applied on top of calculated_price_minor.
 */
final readonly class ResolvedPackagePriceData
{
    public function __construct(
        public int $countryId,
        public int $currencyId,
        public string $currencyCode,
        public int $unitPriceMinor,
        public int $paidQuantity,
        public int $calculatedPriceMinor,
    ) {}
}
