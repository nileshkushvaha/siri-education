<?php

declare(strict_types=1);

namespace App\Earnings\DTOs;

use App\Models\InstructorEarning;
use App\Models\LessonFinancialDisposition;

/**
 * Outcome of a reconciliation attempt. `action` names what actually
 * happened (created / held / restored_released / reversed /
 * earning_intact / no_earning_required / deferred / already_settled);
 * applied=false means an idempotent repeat or a deferral.
 */
final readonly class LessonEarningReconciliationResult
{
    public function __construct(
        public LessonFinancialDisposition $disposition,
        public ?InstructorEarning $earning,
        public string $action,
        public bool $applied,
    ) {}
}
