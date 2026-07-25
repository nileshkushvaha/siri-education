<?php

declare(strict_types=1);

namespace App\Listeners\Alerts;

use App\Alerts\DTOs\OperationalAlertSignal;
use App\Alerts\Enums\OperationalAlertSeverity;
use App\Alerts\Enums\OperationalAlertType;
use App\Alerts\Services\OperationalAlertService;
use App\Alerts\Support\CriticalJobClassifier;
use App\Queue\Support\FailedJobPayloadSummary;
use Illuminate\Queue\Events\JobFailed;
use Throwable;

/**
 * Requirement #4 "critical failed jobs" — consumes Laravel's own
 * `JobFailed` event (fired synchronously by the worker right after
 * `failed_jobs` is written), so no new failed-job tracking is added;
 * this only decides whether an ALREADY-tracked failure is
 * alert-worthy. Deliberately NOT queued: it must never itself be
 * re-queued onto the same failing connection, and running inline
 * inside the worker's own failure handling — after the job's own
 * transaction has already rolled back — cannot affect that job's
 * (already-lost) outcome (requirement #8).
 *
 * `subjectType` here holds the failing job's class name rather than an
 * Eloquent model — there is no single durable business record a
 * generic queue job failure belongs to — so the fingerprint groups
 * repeated failures of the SAME job class, distinct from every other
 * job class.
 */
final class CreateOperationalAlertOnJobFailed
{
    public function __construct(
        private readonly OperationalAlertService $alerts,
    ) {}

    public function handle(JobFailed $event): void
    {
        $displayName = $event->job->resolveName();
        $category = CriticalJobClassifier::category($displayName);

        if ($category === null) {
            return;
        }

        // Requirement #8: this fires from inside the worker's own
        // failure handling, after the job's own outcome is already
        // final — an exception here must never escape into the
        // worker loop, so it is reported and swallowed, never thrown.
        try {
            $this->alerts->createOrMerge(new OperationalAlertSignal(
                type: OperationalAlertType::CriticalFailedJob,
                category: $category,
                severity: OperationalAlertSeverity::Critical,
                title: sprintf('Critical job failed: %s', $displayName),
                summary: sprintf(
                    'Job "%s" on queue "%s" failed: %s',
                    $displayName,
                    $event->job->getQueue(),
                    FailedJobPayloadSummary::exceptionSummary((string) $event->exception),
                ),
                subjectType: $displayName,
                subjectId: null,
                metadata: ['queue' => $event->job->getQueue(), 'connection' => $event->connectionName],
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
