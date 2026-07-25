<?php

declare(strict_types=1);

namespace App\Messaging\Events;

use App\Models\MessageReport;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class MessageReported implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly MessageReport $report,
    ) {}
}
