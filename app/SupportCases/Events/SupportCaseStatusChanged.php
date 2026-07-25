<?php

declare(strict_types=1);

namespace App\SupportCases\Events;

use App\Models\SupportCase;
use App\Models\User;
use App\SupportCases\Enums\SupportCaseStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SupportCaseStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly SupportCase $case,
        public readonly SupportCaseStatus $from,
        public readonly SupportCaseStatus $to,
        public readonly User $actor,
        public readonly ?string $reason = null,
    ) {}
}
