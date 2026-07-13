<?php

declare(strict_types=1);

namespace App\Wallet\Services;

use App\Earnings\Enums\LessonFinancialDispositionStatus;
use App\Earnings\Enums\LessonStudentDisposition;
use App\Earnings\Exceptions\EarningException;
use App\Models\LessonFinancialDisposition;
use App\Models\User;
use App\Settings\InstructorEarningSettings;
use App\Wallet\Actions\ExecuteLessonWalletRefundAction;
use App\Wallet\Contracts\LessonWalletRefundServiceInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates Phase 17F wallet refunds: the kill switch, admin
 * authorization for direct execution, and the batched processor behind
 * lessons:process-refunds. All financial writes live in
 * ExecuteLessonWalletRefundAction; unresolved policy-review cases are
 * never auto-refunded — the query only ever selects approved Ready
 * full-wallet-refund dispositions.
 */
final class LessonWalletRefundService implements LessonWalletRefundServiceInterface
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private readonly ExecuteLessonWalletRefundAction $action,
        private readonly InstructorEarningSettings $settings,
    ) {}

    public function execute(LessonFinancialDisposition $disposition, ?User $admin = null): LessonFinancialDisposition
    {
        if (! $this->settings->lesson_refund_execution_enabled) {
            throw new EarningException('Lesson refund execution is disabled.');
        }

        if ($admin !== null && ! $admin->can('resolveFinancialDisposition', $disposition->lesson)) {
            throw new AuthorizationException('You may not execute lesson refunds.');
        }

        return $this->action->execute($disposition, $admin)->disposition;
    }

    public function processReady(): int
    {
        if (! $this->settings->lesson_refund_execution_enabled) {
            return 0;
        }

        $credited = 0;

        $ready = LessonFinancialDisposition::query()
            ->where('processing_status', LessonFinancialDispositionStatus::Ready)
            ->where('student_disposition', LessonStudentDisposition::FullWalletRefundRequired)
            ->where('admin_hold', false)
            ->whereNull('refund_ledger_entry_id')
            ->lazyById(self::BATCH_SIZE);

        foreach ($ready as $disposition) {
            try {
                $credited += $this->action->execute($disposition)->credited ? 1 : 0;
            } catch (Throwable $e) {
                // One failing refund never stops the batch; the row stays
                // Ready and retries next run. Ids and reasons only.
                Log::warning('lessons:process-refunds — disposition skipped after failure', [
                    'disposition_id' => $disposition->id,
                    'lesson_id' => $disposition->lesson_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $credited;
    }
}
