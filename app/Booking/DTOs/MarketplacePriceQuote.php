<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Booking\Enums\MarketplacePriceState;

/**
 * A read-only, presentation-safe pricing preview for one instructor in
 * one (country, subject, academic level) context — never a second
 * pricing engine, never trusted as a booking input. `options` holds
 * one entry per currently bookable paid duration (usually one, since
 * only one paid booking type is registered today, but the shape
 * supports more without a redesign). `exact` is set only when exactly
 * one option resolved; `lowest` is the minimum-amount option among two
 * or more, used for a "From" display.
 */
final readonly class MarketplacePriceQuote
{
    /**
     * @param  list<MarketplaceLessonPriceOption>  $options
     */
    public function __construct(
        public MarketplacePriceState $state,
        public array $options = [],
        public ?MarketplaceLessonPriceOption $exact = null,
        public ?MarketplaceLessonPriceOption $lowest = null,
        public bool $isFrom = false,
    ) {}

    public static function missingCountry(): self
    {
        return new self(MarketplacePriceState::MissingCountry);
    }

    public static function unavailable(): self
    {
        return new self(MarketplacePriceState::Unavailable);
    }

    /** @param  list<MarketplaceLessonPriceOption>  $options */
    public static function available(array $options): self
    {
        if ($options === []) {
            return self::unavailable();
        }

        $lowest = array_reduce(
            $options,
            static fn (?MarketplaceLessonPriceOption $carry, MarketplaceLessonPriceOption $option): MarketplaceLessonPriceOption => $carry === null || $option->amountMinor < $carry->amountMinor ? $option : $carry,
            null,
        );

        $isMultiple = count($options) > 1;

        return new self(
            MarketplacePriceState::Available,
            $options,
            exact: $isMultiple ? null : $options[0],
            lowest: $lowest,
            isFrom: $isMultiple,
        );
    }
}
