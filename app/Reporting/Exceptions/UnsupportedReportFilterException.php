<?php

declare(strict_types=1);

namespace App\Reporting\Exceptions;

/** An unknown filter enum value was supplied — rejected rather than silently ignored or defaulted. */
final class UnsupportedReportFilterException extends ReportingException
{
    public static function unknownValue(string $filterKey, string $value): self
    {
        return new self(sprintf('"%s" is not a recognized value for the "%s" report filter.', $value, $filterKey));
    }
}
