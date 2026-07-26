<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Earnings\Actions\ExecuteLessonEarningReconciliationAction;
use App\Earnings\Contracts\LessonEarningReconciliationServiceInterface;
use App\Earnings\Enums\LessonFinancialDispositionStatus;
use App\Earnings\Exceptions\EarningException;
use App\Models\LessonFinancialDisposition;
use App\Models\User;
use App\Settings\InstructorEarningSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates the kill switch, admin authorization for direct
 * execution, and the batched processor behind
 * lessons:process-earning-reconciliation. All financial logic lives in
 * ExecuteLessonEarningReconciliationAction; unresolved manual-review
 * cases are never selected.
 */
final class LessonEarningReconciliationService implements LessonEarningReconciliationServiceInterface
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private readonly ExecuteLessonEarningReconciliationAction $action,
        private readonly InstructorEarningSettings $settings,
    ) {}

    public function execute(LessonFinancialDisposition $disposition, ?User $admin = null): LessonFinancialDisposition
    {
        if (! $this->settings->earning_reconciliation_execution_enabled) {
            throw new EarningException('Earning reconciliation execution is disabled.');
        }

        if ($admin !== null && ! $admin->can('resolveFinancialDisposition', $disposition->lesson)) {
            throw new AuthorizationException('You may not execute earning reconciliations.');
        }

        return $this->action->execute($disposition, $admin)->disposition;
    }

    public function processReady(): int
    {
        if (! $this->settings->earning_reconciliation_execution_enabled) {
            return 0;
        }

        $applied = 0;

        $ready = LessonFinancialDisposition::query()
            ->where('processing_status', LessonFinancialDispositionStatus::Ready)
            ->where('admin_hold', false)
            ->whereIn('reason_code', ExecuteLessonEarningReconciliationAction::RECONCILIATION_REASONS)
            ->lazyById(self::BATCH_SIZE);

        foreach ($ready as $disposition) {
            try {
                $applied += $this->action->execute($disposition)->applied ? 1 : 0;
            } catch (Throwable $e) {
                // One failure never stops the batch; the row stays Ready
                // and retries next run. Ids and reasons only.
                Log::warning('lessons:process-earning-reconciliation — disposition skipped after failure', [
                    'disposition_id' => $disposition->id,
                    'lesson_id' => $disposition->lesson_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $applied;
    }
}
