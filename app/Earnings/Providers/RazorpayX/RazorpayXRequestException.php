<?php

declare(strict_types=1);

namespace App\Earnings\Providers\RazorpayX;

use RuntimeException;

/** Low-level transport/API failure — never crosses the InstructorPayoutProviderInterface boundary raw; the adapter always classifies it into a PayoutFailureCategory first. */
final class RazorpayXRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $razorpayErrorCode = null,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
