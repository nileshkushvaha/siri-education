<?php

declare(strict_types=1);

namespace App\Reporting\Exceptions;

/** An explicitly-requested reporting timezone is not a valid IANA identifier — rejected, never silently replaced. */
final class InvalidReportingTimezoneException extends ReportingException
{
    public static function forValue(string $timezone): self
    {
        return new self(sprintf('"%s" is not a valid timezone identifier.', $timezone));
    }
}
