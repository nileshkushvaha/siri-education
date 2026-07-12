<?php

declare(strict_types=1);

namespace App\Booking\Exceptions;

/**
 * The attendance webhook could not be verified or normalized (bad
 * signature, malformed timestamps, unknown event types). The message
 * is safe to log but is never echoed verbatim to the caller.
 */
final class InvalidAttendanceWebhookException extends BookingException {}
