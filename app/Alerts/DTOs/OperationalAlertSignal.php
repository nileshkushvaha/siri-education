<?php

declare(strict_types=1);

namespace App\Alerts\DTOs;

use App\Alerts\Enums\OperationalAlertCategory;
use App\Alerts\Enums\OperationalAlertSeverity;
use App\Alerts\Enums\OperationalAlertType;
use Carbon\CarbonImmutable;

/**
 * Everything one alert source needs to report — `category` is passed
 * explicitly rather than always derived from `type` because one type
 * (`CriticalFailedJob`) legitimately routes differently depending on
 * which job failed (see the `JobFailed` listener's own classifier);
 * every other source simply passes `$type->category()`.
 *
 * `title`/`summary` must never contain credentials, provider payloads,
 * bank details, or private user content — callers are the sole
 * authority on what is "safe" for their own domain, exactly like
 * `BookingPaymentReconciliationService::raiseIssue()`'s existing
 * `$safeSummary` parameter.
 */
final readonly class OperationalAlertSignal
{
    /** @param  array<string, mixed>  $metadata */
    public function __construct(
        public OperationalAlertType $type,
        public OperationalAlertCategory $category,
        public OperationalAlertSeverity $severity,
        public string $title,
        public string $summary,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public array $metadata = [],
        public ?CarbonImmutable $occurredAt = null,
    ) {}
}
