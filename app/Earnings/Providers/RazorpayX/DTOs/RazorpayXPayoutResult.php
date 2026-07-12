<?php

declare(strict_types=1);

namespace App\Earnings\Providers\RazorpayX\DTOs;

/** The raw RazorpayX payout status string is preserved for audit/display, never branched on outside RazorpayXStatusMapper. */
final readonly class RazorpayXPayoutResult
{
    public function __construct(
        public string $payoutId,
        public string $status,
        public ?string $utr,
        public ?int $feesMinor,
        public ?int $taxMinor,
        public string $mode,
        public ?string $referenceId,
        public ?string $failureReason = null,
    ) {}
}
