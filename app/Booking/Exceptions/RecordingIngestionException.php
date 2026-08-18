<?php

declare(strict_types=1);

namespace App\Booking\Exceptions;

use App\Booking\Enums\RecordingFailureCode;
use RuntimeException;
use Throwable;

/**
 * A failure on the SOURCE side of ingestion — fetching, staging, or
 * validating the recording the meeting provider supplied — as opposed
 * to RecordingStorageException, which covers the destination side.
 *
 * Both carry a RecordingFailureCode so RecordingIngestionService makes
 * one retry decision from one enum, regardless of which half of the
 * pipeline failed. Messages are safe diagnostics for logs and admins,
 * never shown verbatim to a student or instructor.
 */
final class RecordingIngestionException extends RuntimeException
{
    public function __construct(
        public readonly RecordingFailureCode $failureCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
