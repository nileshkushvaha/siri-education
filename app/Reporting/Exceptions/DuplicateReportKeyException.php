<?php

declare(strict_types=1);

namespace App\Reporting\Exceptions;

/** Two report definitions were registered under the same stable key. */
final class DuplicateReportKeyException extends ReportingException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf('A report is already registered under the key "%s".', $key));
    }
}
