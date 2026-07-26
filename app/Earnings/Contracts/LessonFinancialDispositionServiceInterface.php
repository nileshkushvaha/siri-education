<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Earnings\Exceptions\EarningException;
use App\Lessons\Enums\LessonOutcome;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use App\Models\LessonFinancialDisposition;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The financial-decision bridge: classifies what the wallet, earning,
 * and cancellation pipelines must do for a finalized lesson — and holds
 * disputed earnings via the existing DisputedHold mechanism. It NEVER
 * credits refunds, reverses ledger entries, creates earnings, or alters
 * amounts; execution is the responsibility of the services these
 * dispositions route to (earning reconciliation, refund).
 */
interface LessonFinancialDispositionServiceInterface
{
    /**
     * Classify a finalized outcome exactly once (unique per lesson;
     * replays of the same outcome are no-ops). Returns null while
     * instructor_earnings.financial_disposition_enabled is off.
     */
    public function classify(Lesson $lesson, LessonOutcome $outcome): ?LessonFinancialDisposition;

    /**
     * Re-evaluate after an outcome override: the previous snapshot is
     * appended to history, the version bumps, and earning/refund
     * conflicts (existing earning, settled earning, completed refund)
     * are detected and routed to holds or manual review.
     */
    public function reevaluate(Lesson $lesson, LessonOutcome $previousOutcome, LessonOutcome $newOutcome, string $overrideReason): ?LessonFinancialDisposition;

    /**
     * Back-fills instructor_earning_id once the earning exists.
     * classify() (on LessonOutcomeFinalized) and earning creation (on
     * LessonCompleted) are two independently after-commit-deferred
     * listeners on the same finalize() transaction with no ordering
     * guarantee between them — classify() already links immediately
     * when the earning already exists; this covers the reverse
     * ordering. A no-op when no disposition exists yet or it is
     * already linked.
     */
    public function linkEarning(Lesson $lesson, InstructorEarning $earning): void;

    /**
     * @throws EarningException
     * @throws AuthorizationException
     */
    public function approve(LessonFinancialDisposition $disposition, User $admin, string $reason): LessonFinancialDisposition;

    /**
     * @throws EarningException
     * @throws AuthorizationException
     */
    public function keepOnHold(LessonFinancialDisposition $disposition, User $admin, string $reason): LessonFinancialDisposition;

    /**
     * @throws EarningException
     * @throws AuthorizationException
     */
    public function reject(LessonFinancialDisposition $disposition, User $admin, string $reason): LessonFinancialDisposition;

    /**
     * @throws EarningException
     * @throws AuthorizationException
     */
    public function markReadyForRefund(LessonFinancialDisposition $disposition, User $admin, string $reason): LessonFinancialDisposition;

    /**
     * @throws EarningException
     * @throws AuthorizationException
     */
    public function markReadyForEarningReconciliation(LessonFinancialDisposition $disposition, User $admin, string $reason): LessonFinancialDisposition;
}
