<?php

declare(strict_types=1);

namespace App\Booking\Exceptions;

use RuntimeException;

/**
 * Low-level transport/SDK failure from a gateway adapter (network
 * error, non-2xx response, SDK-thrown error). Never crosses the
 * PaymentProviderInterface boundary — each provider catches this and
 * raises a BookingException with a safe, provider-contextualized
 * message. Never carries the raw SDK exception's message verbatim
 * into a booking_payments row or a log without review, since some
 * SDK error messages can echo back request parameters.
 */
final class GatewayRequestException extends RuntimeException {}
