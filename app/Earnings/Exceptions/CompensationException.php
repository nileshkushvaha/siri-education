<?php

declare(strict_types=1);

namespace App\Earnings\Exceptions;

/**
 * Compensation-agreement domain failures. Messages must stay safe for
 * the UI — never student pricing, margins, or internal reasons.
 */
class CompensationException extends EarningException {}
