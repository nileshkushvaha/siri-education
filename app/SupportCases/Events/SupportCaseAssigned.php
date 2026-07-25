<?php

declare(strict_types=1);

namespace App\SupportCases\Events;

use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SupportCaseAssigned implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly SupportCase $case,
        public readonly User $assignee,
        public readonly User $actor,
        public readonly bool $isReassignment,
    ) {}
}
