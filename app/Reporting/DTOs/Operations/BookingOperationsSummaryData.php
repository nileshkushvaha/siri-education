<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Operations;

/**
 * Booking Report metrics. Date basis: `bookings.created_at`
 * (business-event view — "bookings created in the period"), except
 * `rescheduled` which counts `booking_activities` rows created in the
 * period (a reschedule can happen well after the booking itself was
 * created). All counts, never revenue/price — financial values are out
 * of scope for this report.
 *
 * @param  array<string, int>  $byType  keyed by `ReportingBookingType::value` ('free_demo', 'paid_one_to_one')
 * @param  array<string, int>  $byStatus  keyed by `BookingStatus::value`
 * @param  array<string, int>  $byRecurrence  keyed by 'single', 'daily', 'weekly', 'unknown_historical' —
 *                                            see `RecurrenceClassifier` for exactly what each bucket means and why "unknown_historical" is never
 *                                            folded into "single".
 */
final readonly class BookingOperationsSummaryData
{
    public function __construct(
        public int $total,
        public array $byType,
        public array $byStatus,
        public array $byRecurrence,
        public int $rescheduled,
    ) {}
}
