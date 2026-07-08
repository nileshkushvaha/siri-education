<?php

declare(strict_types=1);

namespace App\Wallet\Exceptions;

use RuntimeException;

/**
 * Base exception for the Wallet domain, mirroring App\Booking\Exceptions\BookingException.
 */
class WalletException extends RuntimeException {}
