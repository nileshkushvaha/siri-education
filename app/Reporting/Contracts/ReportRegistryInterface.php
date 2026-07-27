<?php

declare(strict_types=1);

namespace App\Reporting\Contracts;

use App\Models\User;
use App\Reporting\DTOs\ReportDefinition;

/**
 * Code-defined report catalogue (SRS §10). Listing never executes
 * a report query and never instantiates a domain service — definitions
 * are plain data built once at construction.
 */
interface ReportRegistryInterface
{
    /** @return list<ReportDefinition> every registered definition, regardless of the caller's permissions. */
    public function all(): array;

    public function find(string $key): ?ReportDefinition;

    /** @return list<ReportDefinition> only definitions the given user is authorized to view. */
    public function availableFor(User $user): array;
}
