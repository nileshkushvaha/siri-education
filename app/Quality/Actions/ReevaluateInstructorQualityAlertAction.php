<?php

declare(strict_types=1);

namespace App\Quality\Actions;

use App\Quality\Contracts\InstructorQualityAlertRepositoryInterface;
use App\Quality\Enums\QualityAlertSourceType;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\DB;

/**
 * A source record underlying an alert changed (a review was hidden/
 * rejected/archived, a lesson outcome was overridden) — the signal
 * that created the alert may no longer be valid. This never deletes
 * the alert or silently changes its resolution status; it only flags
 * `needs_reevaluation` so a human (or `reviews:reconcile-quality-alerts`)
 * can look again. A source with no active alert is a no-op — there is
 * nothing to flag.
 */
final class ReevaluateInstructorQualityAlertAction
{
    private const string LOG_NAME = 'quality';

    public function __construct(
        private readonly InstructorQualityAlertRepositoryInterface $alerts,
        private readonly AuditTrailService $audit,
    ) {}

    public function execute(QualityAlertSourceType $sourceType, string $sourceId): void
    {
        DB::transaction(function () use ($sourceType, $sourceId): void {
            $alert = $this->alerts->findActiveForSource($sourceType, $sourceId);

            if ($alert === null || $alert->needs_reevaluation) {
                return; // nothing active for this source, or already flagged — idempotent
            }

            $alert = $this->alerts->lock($alert);

            if ($alert->needs_reevaluation) {
                return; // re-check post-lock: another process already flagged it
            }

            $alert->fill([
                'needs_reevaluation' => true,
                'reevaluated_at' => now()->toImmutable()->utc(),
                'version' => $alert->version + 1,
            ])->save();

            $this->audit->logSystem(
                self::LOG_NAME,
                'instructor_quality_alert_flagged_for_reevaluation',
                sprintf('Quality alert %s flagged for reevaluation — its source record changed.', $alert->id),
                $alert,
                ['source_type' => $sourceType->value, 'source_id' => $sourceId],
            );
        });
    }
}
