<?php

declare(strict_types=1);

namespace App\Earnings\Events;

use App\Models\InstructorPayoutMethod;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Dispatched after commit — subscription point for reporting phases. */
final class InstructorPayoutMethodRejected
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly InstructorPayoutMethod $method,
    ) {}
}
