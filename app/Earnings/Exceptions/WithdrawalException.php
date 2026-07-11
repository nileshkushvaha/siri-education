<?php

declare(strict_types=1);

namespace App\Earnings\Exceptions;

/**
 * Withdrawal domain failures. Messages must stay safe for the UI —
 * never leak payout details or raw internal financial state.
 */
class WithdrawalException extends EarningException {}
