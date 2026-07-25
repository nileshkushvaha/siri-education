<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\DTOs\MarketplaceLessonPriceOption;
use App\Booking\DTOs\MarketplacePriceQuote;
use App\Booking\Exceptions\BookingException;
use App\Models\AcademicLevel;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\StudentLessonPrice;
use App\Models\Subject;
use App\Models\User;
use App\Support\Financial\CurrencyEligibilityPolicy;
use App\Support\Financial\Exceptions\CurrencyNotUsableException;
use App\Support\Financial\FinancialOperation;
use App\Support\MoneyFormatter;
use Illuminate\Support\Collection;

/**
 * Read-only marketplace preview of the exact price
 * StudentLessonPriceResolver would resolve at checkout — never a
 * second pricing engine. The single-instructor path
 * ({@see quoteFor()}) simply calls the resolver directly, so it is
 * provably identical to checkout by construction. The batch path
 * ({@see batchQuoteFor()}) exists only to avoid one resolver call
 * (up to four queries) per instructor on a paginated listing; it uses
 * {@see StudentLessonPriceRanking}, the same pure ranking rule the
 * resolver's own precedence follows, so the two can never disagree.
 */
final class MarketplaceLessonPriceService
{
    public function __construct(
        private readonly StudentLessonPriceResolver $resolver,
        private readonly CurrencyEligibilityPolicy $currencyEligibility,
    ) {}

    public function quoteFor(User $instructor, ?Country $country, Subject $subject, ?AcademicLevel $academicLevel): MarketplacePriceQuote
    {
        if ($country === null) {
            return MarketplacePriceQuote::missingCountry();
        }

        $options = [];
        $currencyMemo = [];

        foreach ($this->activePaidBookingTypes() as $type) {
            try {
                $price = $this->resolver->resolve($type, $subject, $academicLevel, $type->duration_minutes, $country, $instructor->id);
            } catch (BookingException) {
                continue;
            }

            $option = $this->toOption($price, $currencyMemo);

            if ($option !== null) {
                $options[] = $option;
            }
        }

        return MarketplacePriceQuote::available($options);
    }

    /**
     * @param  Collection<int, User>  $instructors
     * @return Collection<int, MarketplacePriceQuote> keyed by instructor id
     */
    public function batchQuoteFor(Collection $instructors, ?Country $country, Subject $subject, ?AcademicLevel $academicLevel): Collection
    {
        $instructorIds = $instructors->pluck('id')->values();

        if ($country === null || $instructorIds->isEmpty()) {
            return $instructorIds->mapWithKeys(fn (int $id): array => [$id => MarketplacePriceQuote::missingCountry()]);
        }

        $optionsByInstructor = $instructorIds->mapWithKeys(fn (int $id): array => [$id => []])->all();
        $currencyMemo = [];

        foreach ($this->activePaidBookingTypes() as $type) {
            $candidates = StudentLessonPrice::query()
                ->where('booking_type_id', $type->id)
                ->where('subject_id', $subject->id)
                ->where('country_id', $country->id)
                ->where('duration_minutes', $type->duration_minutes)
                ->where(fn ($q) => $q->whereIn('instructor_id', $instructorIds->all())->orWhereNull('instructor_id'))
                ->active()
                ->effectiveNow()
                ->get();

            if ($candidates->isEmpty()) {
                continue;
            }

            foreach ($instructorIds as $instructorId) {
                $winner = StudentLessonPriceRanking::winner($candidates, $instructorId, $academicLevel?->id);
                $option = $winner !== null ? $this->toOption($winner, $currencyMemo) : null;

                if ($option !== null) {
                    $optionsByInstructor[$instructorId][] = $option;
                }
            }
        }

        return collect($optionsByInstructor)->map(
            fn (array $options): MarketplacePriceQuote => MarketplacePriceQuote::available($options),
        );
    }

    /** @return Collection<int, BookingType> */
    private function activePaidBookingTypes(): Collection
    {
        return BookingType::query()
            ->where('is_paid', true)
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * @param  array<string, bool>  $currencyMemo  per-call cache keyed by
     *                                             uppercase currency code —
     *                                             a page of instructors in
     *                                             one country typically
     *                                             shares one or two
     *                                             currencies, so this keeps
     *                                             the eligibility check
     *                                             from becoming a query per
     *                                             resolved price.
     */
    private function toOption(StudentLessonPrice $price, array &$currencyMemo): ?MarketplaceLessonPriceOption
    {
        $code = strtoupper($price->currency_code);

        if (! array_key_exists($code, $currencyMemo)) {
            try {
                $this->currencyEligibility->assertUsable($code, FinancialOperation::NewInitiation);
                $currencyMemo[$code] = true;
            } catch (CurrencyNotUsableException) {
                $currencyMemo[$code] = false;
            }
        }

        if (! $currencyMemo[$code]) {
            return null;
        }

        return new MarketplaceLessonPriceOption(
            durationMinutes: $price->duration_minutes,
            amountMinor: $price->amount_minor,
            formattedAmount: MoneyFormatter::format($price->amount_minor, $price->currency_code),
            currencyCode: $price->currency_code,
            isInstructorOverride: $price->instructor_id !== null,
        );
    }
}
