<?php

declare(strict_types=1);

namespace App\Quality\DTOs;

use App\Quality\Enums\InstructorQualityAlertSeverity;
use App\Quality\Enums\InstructorQualityAlertStatus;
use App\Quality\Enums\InstructorQualityAlertType;
use Carbon\CarbonImmutable;

final readonly class AlertQueueFilters
{
    public function __construct(
        public ?InstructorQualityAlertType $type = null,
        public ?InstructorQualityAlertSeverity $severity = null,
        public ?InstructorQualityAlertStatus $status = null,
        public ?int $assignedTo = null,
        public ?int $instructorId = null,
        public ?CarbonImmutable $triggeredFrom = null,
        public ?CarbonImmutable $triggeredUntil = null,
    ) {}
}
