<?php

declare(strict_types=1);

namespace App\Listeners\Compliance;

use App\Compliance\Rules\RepeatedFailedLoginsRule;
use App\Compliance\Services\ComplianceMonitoringService;
use App\Events\Auth\LoginFailed;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Thin trigger — all detection logic lives in RepeatedFailedLoginsRule;
 * all writing lives in ComplianceMonitoringService. Queued so
 * evaluation never runs inline with the login-failure request/response
 * cycle, and so a monitoring failure can never affect the source
 * authentication flow.
 */
final class EvaluateRepeatedFailedLoginsOnLoginFailed implements ShouldQueue
{
    public string $queue = 'compliance';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly RepeatedFailedLoginsRule $rule,
        private readonly ComplianceMonitoringService $compliance,
    ) {}

    public function handle(LoginFailed $event): void
    {
        if ($event->user === null) {
            return; // cannot attribute an unknown email to a subject
        }

        $signal = $this->rule->evaluate($event->user);

        if ($signal !== null) {
            $this->compliance->record($signal);
        }
    }
}
