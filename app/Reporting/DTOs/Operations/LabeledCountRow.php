<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Operations;

/** One row of a bounded top-N distribution breakdown (by subject, instructor, country, or duration). */
final readonly class LabeledCountRow
{
    public function __construct(
        public string $label,
        public int $count,
    ) {}
}
