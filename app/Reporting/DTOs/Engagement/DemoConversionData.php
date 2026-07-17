<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Engagement;

/**
 * Phase 18D §6.6 (Outcome A) — demo-to-paid conversion, reusing the
 * EXISTING authoritative definition (`BookingAnalyticsRepository::conversion()`,
 * covered by BookingAnalyticsTest) verbatim:
 *
 * - Demo booker: distinct student with a `free_demo` booking created in
 *   the period (any status — a cancelled demo still counts).
 * - Converted: that student has ANY paid-type booking created at or
 *   after the demo's creation (any status, any instructor, any subject,
 *   unbounded attribution window).
 * - Counted once per student.
 *
 * Because this definition carries NO instructor/subject/country
 * attribution, per-instructor/subject/country conversion is left
 * UNAVAILABLE rather than invented (§8.5). `conversionRate` is null —
 * never a fabricated 0% — when there are no demo bookers in the period.
 */
final readonly class DemoConversionData
{
    public function __construct(
        public int $demoBookers,
        public int $convertedBookers,
        public ?float $conversionRate,
    ) {}
}
