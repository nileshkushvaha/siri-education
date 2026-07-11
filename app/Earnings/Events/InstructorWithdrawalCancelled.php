<?php

declare(strict_types=1);

namespace App\Earnings\Events;

use App\Models\InstructorWithdrawalRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Dispatched after commit — subscription point for reporting phases. */
final class InstructorWithdrawalCancelled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly InstructorWithdrawalRequest $withdrawal,
    ) {}
}
