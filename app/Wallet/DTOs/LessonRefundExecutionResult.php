<?php

declare(strict_types=1);

namespace App\Wallet\DTOs;

use App\Models\LessonFinancialDisposition;
use App\Models\WalletLedgerEntry;

/**
 * Outcome of a refund-execution attempt. credited=true only when a new
 * wallet credit was posted this call; an idempotent repeat returns the
 * existing entry with credited=false, and a deferral (manual review /
 * already-refunded) returns no new credit.
 */
final readonly class LessonRefundExecutionResult
{
    public function __construct(
        public LessonFinancialDisposition $disposition,
        public ?WalletLedgerEntry $entry,
        public bool $credited,
    ) {}
}
