<?php

declare(strict_types=1);

namespace App\Quality\DTOs;

use App\Reviews\Enums\ReviewReportReason;
use App\Reviews\Enums\ReviewReportStatus;
use Carbon\CarbonImmutable;

final readonly class ReportQueueFilters
{
    public function __construct(
        public ?ReviewReportStatus $status = null,
        public ?ReviewReportReason $reason = null,
        public ?int $instructorId = null,
        public ?CarbonImmutable $submittedFrom = null,
        public ?CarbonImmutable $submittedUntil = null,
    ) {}
}
