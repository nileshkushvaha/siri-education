<?php

declare(strict_types=1);

namespace App\Messaging\Safety\Events;

use App\Models\MessageSafetyFinding;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An administrator agreed that a finding warrants attention.
 *
 * The Messaging domain announces the human decision; the Compliance
 * domain decides whether a pattern of them is worth an account-level
 * flag. Keeping that as an event rather than a direct call is what
 * stops the safety service depending on the compliance pipeline —
 * mirroring how MessageReported already reaches
 * EvaluateRepeatedMessageReportsOnMessageReported.
 */
final class MessageSafetyFindingConfirmed implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly MessageSafetyFinding $finding,
    ) {}
}
