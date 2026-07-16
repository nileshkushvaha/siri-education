<?php

declare(strict_types=1);

namespace App\Reporting\Exceptions;

/** Two metric definitions were registered under the same stable key. */
final class DuplicateMetricKeyException extends ReportingException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf('A metric is already registered under the key "%s".', $key));
    }
}
