<?php

declare(strict_types=1);

namespace App\Support\Financial\Exceptions;

use RuntimeException;

/**
 * Thrown only for NewInitiation when the currency
 * does not exist, is soft-deleted, or is not Active. The message is
 * always the safe, generic, user-facing text — never provider/routing
 * configuration, never an internal currency ID.
 */
class CurrencyNotUsableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This currency is currently unavailable for new payments. Please contact support.');
    }
}
