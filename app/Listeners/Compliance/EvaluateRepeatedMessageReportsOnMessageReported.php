<?php

declare(strict_types=1);

namespace App\Listeners\Compliance;

use App\Compliance\Rules\RepeatedMessageReportsRule;
use App\Compliance\Services\ComplianceMonitoringService;
use App\Messaging\Events\MessageReported;
use Illuminate\Contracts\Queue\ShouldQueue;

final class EvaluateRepeatedMessageReportsOnMessageReported implements ShouldQueue
{
    public string $queue = 'compliance';

    public function __construct(
        private readonly RepeatedMessageReportsRule $rule,
        private readonly ComplianceMonitoringService $service,
    ) {}

    public function handle(MessageReported $event): void
    {
        $signal = $this->rule->evaluate($event->report->message->sender_id);

        if ($signal !== null) {
            $this->service->record($signal);
        }
    }
}
