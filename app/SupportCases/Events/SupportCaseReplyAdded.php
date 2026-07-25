<?php

declare(strict_types=1);

namespace App\SupportCases\Events;

use App\Models\SupportCaseReply;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SupportCaseReplyAdded implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly SupportCaseReply $reply,
    ) {}
}
