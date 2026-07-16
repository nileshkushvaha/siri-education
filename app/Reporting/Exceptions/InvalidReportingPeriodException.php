<?php

declare(strict_types=1);

namespace App\Reporting\Exceptions;

/** A custom date range is invalid — end before start, or wider than the configured maximum. */
final class InvalidReportingPeriodException extends ReportingException
{
    public static function endBeforeStart(): self
    {
        return new self('The report end date cannot be before the start date.');
    }

    public static function rangeTooWide(int $requestedDays, int $maxDays): self
    {
        return new self(sprintf(
            'The requested range (%d days) exceeds the maximum of %d days for a single report.',
            $requestedDays,
            $maxDays,
        ));
    }
}
