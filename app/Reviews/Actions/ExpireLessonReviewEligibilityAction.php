<?php

declare(strict_types=1);

namespace App\Reviews\Actions;

use App\Models\LessonReviewEligibility;
use App\Reviews\Contracts\LessonReviewEligibilityRepositoryInterface;
use App\Reviews\Enums\LessonReviewEligibilityStatus;
use App\Reviews\Events\LessonReviewEligibilityExpired;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\DB;

/**
 * Expires one overdue Open eligibility. Row-locked and re-checked
 * under the lock, so a concurrent submission (future review-submission
 * phase) or a duplicate sweep run can never double-expire or clobber a
 * record that changed state in the meantime. Used/Revoked/ManualReview
 * records are structurally untouchable here — only Open is selected.
 */
final class ExpireLessonReviewEligibilityAction
{
    private const string LOG_NAME = 'reviews';

    public function __construct(
        private readonly LessonReviewEligibilityRepositoryInterface $eligibilities,
        private readonly AuditTrailService $audit,
    ) {}

    /** @return bool true when this call actually expired the record */
    public function execute(LessonReviewEligibility $eligibility): bool
    {
        return DB::transaction(function () use ($eligibility): bool {
            $eligibility = $this->eligibilities->lock($eligibility);

            if ($eligibility->status !== LessonReviewEligibilityStatus::Open
                || $eligibility->expires_at->isFuture()) {
                return false; // already settled by something else, or not due yet
            }

            $eligibility->history = [...($eligibility->history ?? []), $eligibility->snapshotForHistory()];
            $eligibility->fill([
                'status' => LessonReviewEligibilityStatus::Expired,
                'version' => $eligibility->version + 1,
            ])->save();

            $this->audit->logSystem(
                self::LOG_NAME,
                'review_eligibility_expired',
                sprintf('Review eligibility expired for lesson %s.', $eligibility->lesson_id),
                $eligibility,
                ['lesson_id' => $eligibility->lesson_id, 'student_id' => $eligibility->student_id],
            );

            LessonReviewEligibilityExpired::dispatch($eligibility);

            return true;
        });
    }
}
