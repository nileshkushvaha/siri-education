<?php

declare(strict_types=1);

namespace App\Listeners\Messaging;

use App\Messaging\Events\MessageSent;
use App\Messaging\Safety\Contracts\MessageSafetyServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * THE ONE PLACE IN THE PLATFORM WHERE AI ANALYSIS IS NOT HUMAN-INITIATED.
 *
 * P1-P3 all required a person to press a button, and that human
 * initiation was doing real privacy work — it was what made "content
 * only leaves because someone chose to send it" true. Moderation cannot
 * work that way: a safety check that runs only when asked is not a
 * safety check.
 *
 * So this listener exists, and the compensating controls sit one layer
 * down in MessageSafetyService:
 *
 *   - the deterministic layer answers the obvious cases for free, and
 *     those messages are never sent to a provider;
 *   - AmbiguousIntentDetector gates everything else, so only the small
 *     residue of genuinely suggestive phrasing is analysed;
 *   - the input is one message with no history, no names and no ids.
 *
 * Queued on `compliance`, matching the existing compliance listener —
 * message delivery must never wait on safety work, and a provider
 * outage must never affect whether a student can send a message.
 */
final class AnalyseSentMessageForSafety implements ShouldQueue
{
    public string $queue = 'compliance';

    public function __construct(
        private readonly MessageSafetyServiceInterface $safety,
    ) {}

    public function handle(MessageSent $event): void
    {
        // Records what the deterministic detector already found during
        // send. No provider involved, always runs.
        $this->safety->recordDeterministicFinding($event->message);

        // Queues an AI analysis only if the triage gate says this
        // message is genuinely ambiguous — usually it does not.
        $this->safety->requestIntentAnalysis($event->message);
    }
}
