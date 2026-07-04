<?php

declare(strict_types=1);

namespace App\Booking\Validation;

use App\Booking\Contracts\BookingRuleInterface;
use App\Booking\Contracts\BookingTypeInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Exceptions\BookingException;
use Illuminate\Contracts\Container\Container;

/**
 * Runs domain-validation rules before a booking is persisted:
 * global rules first, then the type's own rules. Rules are resolved
 * from the container so they can inject repositories or settings.
 */
final class BookingValidationPipeline
{
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @param  list<class-string<BookingRuleInterface>>  $globalRules
     *
     * @throws BookingException on the first violated rule
     */
    public function run(CreateBookingData $data, BookingTypeInterface $type, array $globalRules = []): void
    {
        foreach ([...$globalRules, ...$type->rules()] as $ruleClass) {
            /** @var BookingRuleInterface $rule */
            $rule = $this->container->make($ruleClass);

            $rule->check($data, $type);
        }
    }
}
