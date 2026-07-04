<?php

declare(strict_types=1);

namespace App\Booking\Exceptions;

use RuntimeException;

/**
 * Base exception for the Booking domain. Callers may catch this
 * to handle any booking failure; subclasses carry specifics.
 */
class BookingException extends RuntimeException {}
