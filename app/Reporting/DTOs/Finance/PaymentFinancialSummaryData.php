<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Finance;

/**
 * Payment collection summary. Financial dictionary (SRS §5):
 *
 * - "Successful external collection" = a `booking_payments` attempt in
 *   `Captured` (or `Refunded`, which was captured before being
 *   refunded) — the attempt-level provider ground truth, timestamped
 *   by `paid_at`. Booking-level `bookings.payment_status` is a
 *   separate snapshot and is NOT this metric's source.
 * - `successRate`: numerator = captured-at-some-point attempts
 *   (Captured + Refunded), denominator = TERMINAL attempts only
 *   (Captured/Failed/Cancelled/Expired/Refunded) — pending/authorized/
 *   processing/unknown/resolution-required attempts are not yet
 *   outcomes. Null — never 0% — when no terminal attempts exist.
 * - `grossPaidBookingValueByCurrency` = SUM(bookings.price) where
 *   `payment_status = 'paid'`, by booking `created_at` — the SAME
 *   semantics as the legacy Booking Reports "revenue" figure, but
 *   currency-grouped (the legacy figure sums across currencies; that
 *   page is untouched and the discrepancy is documented). This is
 *   COMMERCIAL VALUE, deliberately never labeled revenue (§7 Outcome
 *   B), and never added to collections or wallet consumption.
 * - Every wallet-recharge figure is structurally absent: no recharge
 *   flow exists in the codebase (RechargePending/Confirmed have zero
 *   writers) — absence is reported, never fabricated as ₹0 revenue.
 *
 * @param  array<string, int>  $capturedAmountByCurrency  minor units
 * @param  array<string, int>  $averageCapturedByCurrency  minor units (integer division, floor)
 * @param  array<string, int>  $grossPaidBookingValueByCurrency  minor units
 * @param  list<array{provider: string, status: string, count: int}>  $byProviderStatus
 */
final readonly class PaymentFinancialSummaryData
{
    public function __construct(
        public int $attempts,
        public int $captured,
        public int $failed,
        public int $pending,
        public int $cancelledOrExpired,
        public ?float $successRate,
        public array $capturedAmountByCurrency,
        public array $averageCapturedByCurrency,
        public array $grossPaidBookingValueByCurrency,
        public array $byProviderStatus,
        public int $duplicateProviderEvents,
        public int $openReconciliationIssues,
    ) {}
}
