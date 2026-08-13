<?php

declare(strict_types=1);

namespace App\Payments\Exceptions;

use RuntimeException;

/**
 * Base exception for the generic payment domain (App\Payments\*).
 * Distinct from App\Booking\Exceptions\BookingException, which remains
 * the legacy booking-payment path's own exception — the two payment
 * paths are deliberately not merged in this phase.
 */
class PaymentException extends RuntimeException {}
