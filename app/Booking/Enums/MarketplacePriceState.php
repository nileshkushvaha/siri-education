<?php

declare(strict_types=1);

namespace App\Booking\Enums;

/**
 * Why a MarketplacePriceQuote does or does not carry a price — never
 * collapsed to a bare null, so the UI can render an accurate, non-
 * financial reason instead of implying a free lesson.
 */
enum MarketplacePriceState: string
{
    /** At least one paid duration resolved to an active, effective price. */
    case Available = 'available';

    /** No billing country context (guest with none selected, or a student with none on file). */
    case MissingCountry = 'missing_country';

    /** Country (and subject) are known, but no active StudentLessonPrice row matches — a configuration gap, never a free lesson. */
    case Unavailable = 'unavailable';
}
