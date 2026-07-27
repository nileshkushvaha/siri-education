<?php

declare(strict_types=1);

namespace App\DTOs\Instructor;

/** Current-vs-previous-period lesson counts. $hasComparison is false only for the AllTime period, which has no meaningful "previous" window. */
final readonly class LessonTrendData
{
    public function __construct(
        public int $completedCurrent,
        public int $completedPrevious,
        public ?float $completedChangePercent,
        public int $cancelledCurrent,
        public int $cancelledPrevious,
        public int $noShowCurrent,
        public int $noShowPrevious,
        public bool $hasComparison,
    ) {}
}
