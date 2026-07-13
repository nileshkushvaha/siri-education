<?php

declare(strict_types=1);

namespace App\Lessons\DTOs;

use App\Lessons\Enums\TechnicalIssueCategory;
use Carbon\CarbonImmutable;

/**
 * A participant's meeting-problem report. Reporter role and lesson are
 * derived from the authenticated user, never from this payload; the
 * description is sanitized before storage.
 */
final readonly class TechnicalIssueReportData
{
    public function __construct(
        public TechnicalIssueCategory $category,
        public ?string $description = null,
        public ?CarbonImmutable $occurredAt = null,
    ) {}
}
