<?php

declare(strict_types=1);

namespace App\Earnings\Events;

use App\Models\InstructorEarning;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** No listeners this phase — the hook future notification/reporting phases subscribe to. */
final class InstructorEarningCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly InstructorEarning $earning,
    ) {}
}
