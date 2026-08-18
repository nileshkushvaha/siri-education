<?php

declare(strict_types=1);

namespace App\Listeners\Compliance;

use App\Compliance\Rules\RepeatedConfirmedMessageRisksRule;
use App\Compliance\Services\ComplianceMonitoringService;
use App\Messaging\Safety\Events\MessageSafetyFindingConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Escalates a PATTERN of administrator-confirmed message risks into the
 * existing compliance queue — the same shape as
 * EvaluateRepeatedMessageReportsOnMessageReported, deliberately, so
 * account-level review keeps exactly one screen and one workflow.
 */
final class EvaluateConfirmedMessageRisksOnFindingConfirmed implements ShouldQueue
{
    public string $queue = 'compliance';

    public function __construct(
        private readonly RepeatedConfirmedMessageRisksRule $rule,
        private readonly ComplianceMonitoringService $service,
    ) {}

    public function handle(MessageSafetyFindingConfirmed $event): void
    {
        $signal = $this->rule->evaluate((int) $event->finding->sender_id);

        if ($signal !== null) {
            $this->service->record($signal);
        }
    }
}
