<?php

declare(strict_types=1);

namespace App\Listeners\Compliance;

use App\Booking\Events\BookingCancelled;
use App\Compliance\Rules\ExcessiveBookingCancellationsRule;
use App\Compliance\Services\ComplianceMonitoringService;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Thin trigger — all detection logic lives in ExcessiveBookingCancellationsRule. */
final class EvaluateExcessiveBookingCancellationsOnBookingCancelled implements ShouldQueue
{
    public string $queue = 'compliance';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly ExcessiveBookingCancellationsRule $rule,
        private readonly ComplianceMonitoringService $compliance,
    ) {}

    public function handle(BookingCancelled $event): void
    {
        $signal = $this->rule->evaluate($event->booking, $event->context->cancelledBy);

        if ($signal !== null) {
            $this->compliance->record($signal);
        }
    }
}
