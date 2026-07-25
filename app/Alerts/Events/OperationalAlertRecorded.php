<?php

declare(strict_types=1);

namespace App\Alerts\Events;

use App\Models\OperationalAlert;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired only when a NEW alert row is created — never on a merge into an existing active occurrence. */
final class OperationalAlertRecorded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly OperationalAlert $alert,
    ) {}
}
