<?php

declare(strict_types=1);

namespace App\Reviews\Contracts;

use App\Models\LessonReview;
use App\Models\ReviewReport;
use App\Models\User;
use App\Reviews\DTOs\ReviewReportAdminData;
use App\Reviews\DTOs\SubmitReviewReportData;
use App\Reviews\Enums\ReviewReportResolutionAction;

/**
 * Single entry point for report submission and administrative
 * resolution. Every review-status change a resolution triggers
 * delegates to ReviewModerationServiceInterface — this service never
 * writes LessonReview.status directly.
 */
interface ReviewReportServiceInterface
{
    /**
     * Report an eligible published public review. Idempotent against a
     * duplicate active (Pending/UnderReview) report by the same
     * reporter for the same reason — that case throws rather than
     * silently succeeding, since a report is a distinct user action
     * the caller should be told about.
     */
    public function submit(LessonReview $review, User $reporter, SubmitReviewReportData $data): ReviewReport;

    /** Pending → UnderReview. Idempotent if already UnderReview. */
    public function startReview(ReviewReport $report, User $admin): ReviewReport;

    /**
     * The report was valid. Requires a resolution reason. `$action`
     * may optionally hide/reject/archive the underlying review
     * (delegated to ReviewModerationServiceInterface) — RestoreReview
     * is not a valid uphold action. Idempotent if already Upheld.
     */
    public function uphold(ReviewReport $report, User $admin, string $resolutionReason, ReviewReportResolutionAction $action = ReviewReportResolutionAction::NoAction): ReviewReport;

    /**
     * The report was not valid. Requires a resolution reason. `$action`
     * may optionally restore a review some other report already caused
     * to be hidden — Hide/Reject/Archive are not valid dismiss actions.
     * Idempotent if already Dismissed.
     */
    public function dismiss(ReviewReport $report, User $admin, string $resolutionReason, ReviewReportResolutionAction $action = ReviewReportResolutionAction::NoAction): ReviewReport;

    /** This report duplicates another already-handled report against the same review. Never changes review status. Idempotent if already Duplicate. */
    public function markDuplicate(ReviewReport $report, User $admin, ?string $resolutionReason = null): ReviewReport;

    /** Once one report against a review has been resolved, mark every other still-Pending/UnderReview report for that review as Duplicate. Returns the count resolved. */
    public function markRemainingPendingAsDuplicate(LessonReview $review, User $admin, string $resolutionReason): int;

    /** Privacy-safe projection for a future admin UI. */
    public function adminProjection(ReviewReport $report): ReviewReportAdminData;
}
