<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

/** Headline KPIs for a reporting period. Rates are percentages (0–100). */
final readonly class BookingKpiData
{
    public function __construct(
        public int $totalBookings,
        public int $demoRequests,
        public int $demoBookers,
        public int $convertedBookers,
        public float $conversionRate,
        public string $revenue,
        public string $refunded,
        public int $cancelled,
        public float $cancellationRate,
        public int $completed,
    ) {}
}
