<?php

declare(strict_types=1);

namespace App\Earnings\Events;

use App\Models\InstructorSettlementBatch;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** No listeners this phase — the hook future notification/reporting phases subscribe to. */
final class InstructorSettlementPaid
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly InstructorSettlementBatch $batch,
    ) {}
}
