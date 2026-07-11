<?php

declare(strict_types=1);

namespace App\Earnings\Exceptions;

use App\Earnings\Enums\CompensationExceptionCategory;

/**
 * A categorized earning-creation block: the lesson is eligible but
 * compensation cannot be resolved (missing/invalid agreement, invalid
 * currency, unsupported duration). The message must stay UI-safe; the
 * category drives exception recording and the retry policy.
 */
class CompensationBlockedException extends CompensationException
{
    public function __construct(
        public readonly CompensationExceptionCategory $category,
        string $safeReason,
    ) {
        parent::__construct($safeReason);
    }
}
