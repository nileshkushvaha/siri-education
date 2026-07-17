<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Marketplace;

use App\Reporting\DTOs\Operations\LabeledCountRow;

/**
 * Phase 18H — marketplace demand: PERIOD-EVENT booking activity
 * (bookings created in the period, the Phase 18C basis) plus two
 * clearly-labelled interest signals. Subject attribution follows the
 * Phase 18C owner exactly (via `lessons.subject_id` — bookings carry
 * no subject FK); recurrence uses RecurrenceClassifier with
 * `unknown_historical` kept separate, never folded into single.
 * `preferredSubjectInterest` is a self-selected PREFERENCE (Phase 18D
 * labelling) and `activeGoalDemandBySubject` counts currently-Active
 * learning goals per subject — interest signals, never bookings.
 * Weekday buckets use the reporting timezone.
 *
 * @param  array<string, int>  $byRecurrence  single|daily|weekly|unknown_historical
 * @param  list<LabeledCountRow>  $bySubject  bookings per subject (via lesson)
 * @param  list<LabeledCountRow>  $byCountry  bookings per student current profile country
 * @param  list<LabeledCountRow>  $activeGoalDemandBySubject
 * @param  list<LabeledCountRow>  $preferredSubjectInterest
 * @param  array<string, int>  $byWeekday  weekday name => bookings starting that weekday (reporting timezone)
 */
final readonly class MarketplaceDemandData
{
    public function __construct(
        public int $bookingsInPeriod,
        public int $studentsWithBookings,
        public int $demoBookings,
        public int $paidBookings,
        public array $byRecurrence,
        public array $bySubject,
        public array $byCountry,
        public array $activeGoalDemandBySubject,
        public array $preferredSubjectInterest,
        public array $byWeekday,
    ) {}
}
