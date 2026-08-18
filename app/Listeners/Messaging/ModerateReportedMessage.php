<?php

declare(strict_types=1);

namespace App\Listeners\Messaging;

use App\Messaging\Events\MessageReported;
use App\Messaging\Safety\Contracts\MessageSafetyServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Runs the safety classifier on a message a human has REPORTED.
 *
 * Deliberately triggered by the report rather than by sending: abuse
 * classification of every message anyone writes would be the blanket
 * surveillance this phase excludes, and it would be worse value too —
 * the classifier is most useful at the moment an administrator has a
 * report to triage.
 *
 * The reporter is the initiating human, which keeps this closer to the
 * P1-P3 pattern than the intent listener beside it.
 */
final class ModerateReportedMessage implements ShouldQueue
{
    public string $queue = 'compliance';

    public function __construct(
        private readonly MessageSafetyServiceInterface $safety,
    ) {}

    public function handle(MessageReported $event): void
    {
        $message = $event->report->message;

        if ($message === null) {
            return;
        }

        $this->safety->requestModeration($message);
    }
}
