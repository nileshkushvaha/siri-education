<?php

declare(strict_types=1);

namespace App\Earnings\Exceptions;

/**
 * Payout-method domain failures. Messages must stay safe for the UI —
 * never include account numbers, IBANs, routing data, or the encrypted
 * payload. Extends EarningException so panel/action error handling can
 * treat the whole earnings domain uniformly.
 */
class PayoutMethodException extends EarningException {}
