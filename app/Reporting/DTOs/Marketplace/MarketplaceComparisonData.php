<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Marketplace;

/**
 * Supply/demand comparison on COMPATIBLE dimensions only
 * (subject↔subject, country↔country). No composite score, no
 * instructor ranking, no unmet-demand inference from searches or
 * waitlists (neither domain exists). `demandPerActiveInstructor` is a
 * plainly-labelled ratio (period bookings ÷ current active
 * instructors), null when no instructor is active — never a "health
 * score".
 *
 * @param  list<MarketplaceSubjectGapRow>  $subjectGaps  subjects where one side is zero
 * @param  list<string>  $countriesWithDemandNoSupply  countries with period booking demand and zero active instructors
 */
final readonly class MarketplaceComparisonData
{
    public function __construct(
        public ?float $demandPerActiveInstructor,
        public array $subjectGaps,
        public int $activeInstructorsWithoutBookings,
        public array $countriesWithDemandNoSupply,
    ) {}
}
