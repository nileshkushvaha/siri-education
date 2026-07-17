<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Finance;

use Carbon\CarbonImmutable;

/**
 * One row of a reconciliation queue (payment or payout) — safe reason
 * codes and masked references only, never raw provider payloads. Read-
 * only: resolution happens on the existing issue resources, never here.
 */
final readonly class ReconciliationIssueRow
{
    public function __construct(
        public string $reference,
        public string $provider,
        public string $typeLabel,
        public string $severityLabel,
        public string $statusLabel,
        public ?int $amountMinor,
        public ?string $currency,
        public string $safeSummary,
        public CarbonImmutable $firstDetectedAtUtc,
        public ?string $drillDownUrl,
    ) {}
}
