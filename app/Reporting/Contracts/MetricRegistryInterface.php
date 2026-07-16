<?php

declare(strict_types=1);

namespace App\Reporting\Contracts;

use App\Reporting\DTOs\MetricDefinition;

/** Code-defined metric catalogue (Phase 18B §11). Listing never executes a metric calculation. */
interface MetricRegistryInterface
{
    /** @return list<MetricDefinition> */
    public function all(): array;

    public function find(string $key): ?MetricDefinition;
}
