<?php

declare(strict_types=1);

namespace App\Reviews\Services;

use App\Models\LessonReview;
use App\Models\ReviewReport;
use App\Models\User;
use App\Reviews\Actions\SubmitReviewReportAction;
use App\Reviews\Actions\TransitionReviewReportStatusAction;
use App\Reviews\Contracts\ReviewModerationServiceInterface;
use App\Reviews\Contracts\ReviewReportRepositoryInterface;
use App\Reviews\Contracts\ReviewReportServiceInterface;
use App\Reviews\DTOs\ReviewReportAdminData;
use App\Reviews\DTOs\SubmitReviewReportData;
use App\Reviews\Enums\ReviewReportResolutionAction;
use App\Reviews\Enums\ReviewReportStatus;
use App\Reviews\Events\ReviewReportDismissed;
use App\Reviews\Events\ReviewReportMarkedDuplicate;
use App\Reviews\Events\ReviewReportReviewStarted;
use App\Reviews\Events\ReviewReportUpheld;
use App\Reviews\Exceptions\ReviewValidationException;
use App\Services\AuditTrailService;
use App\Services\Student\StudentLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Administrative report resolution. Never touches `lesson_reviews`
 * directly — a resolution action that should change the review's
 * public visibility always calls ReviewModerationServiceInterface,
 * exactly the same way an admin would moderate the review directly.
 * A repeated identical resolution (current status already equals the
 * target) is an idempotent no-op; a resolution that conflicts with a
 * status already reached some other way throws.
 */
final class ReviewReportService implements ReviewReportServiceInterface
{
    private const string LOG_NAME = 'reviews';

    private const array UPHOLD_ACTIONS = [
        ReviewReportResolutionAction::NoAction,
        ReviewReportResolutionAction::HideReview,
        ReviewReportResolutionAction::RejectReview,
        ReviewReportResolutionAction::ArchiveReview,
    ];

    private const array DISMISS_ACTIONS = [
        ReviewReportResolutionAction::NoAction,
        ReviewReportResolutionAction::RestoreReview,
    ];

    public function __construct(
        private readonly SubmitReviewReportAction $submitAction,
        private readonly TransitionReviewReportStatusAction $transition,
        private readonly ReviewReportRepositoryInterface $reports,
        private readonly ReviewModerationServiceInterface $moderation,
        private readonly AuditTrailService $audit,
        private readonly StudentLifecycleService $lifecycle,
    ) {}

    public function submit(LessonReview $review, User $reporter, SubmitReviewReportData $data): ReviewReport
    {
        $this->authorizeReport($reporter, $review);

        // Reporting is open to any authorized active user
        // (LessonReviewPolicy::report), so the lifecycle guard applies
        // only when the reporter IS a student — an
        // instructor/staff reporter without the student role is governed
        // solely by the existing permission check above.
        if ($reporter->hasRole('student')) {
            $this->lifecycle->assertEligibleForStudentAction($reporter);
        }

        return $this->submitAction->execute($review, $reporter, $data);
    }

    public function startReview(ReviewReport $report, User $admin): ReviewReport
    {
        $this->authorizeResolve($admin, $report);

        return DB::transaction(function () use ($report, $admin): ReviewReport {
            $report = $this->reports->lock($report);

            if ($report->status === ReviewReportStatus::UnderReview) {
                return $report;
            }

            $previous = $report->status;
            $report = $this->transition->execute($report, ReviewReportStatus::UnderReview, [
                'reviewed_at' => now()->toImmutable()->utc(),
                'reviewed_by' => $admin->id,
            ]);

            $this->auditTransition($admin, 'review_report_review_started', $report, $previous, ReviewReportStatus::UnderReview, null);
            ReviewReportReviewStarted::dispatch($report);

            return $report;
        });
    }

    public function uphold(ReviewReport $report, User $admin, string $resolutionReason, ReviewReportResolutionAction $action = ReviewReportResolutionAction::NoAction): ReviewReport
    {
        $this->authorizeResolve($admin, $report);
        $this->assertResolutionReason($resolutionReason);
        $this->assertActionAllowed($action, self::UPHOLD_ACTIONS, 'upheld');

        return DB::transaction(function () use ($report, $admin, $resolutionReason, $action): ReviewReport {
            $report = $this->reports->lock($report);

            if ($report->status === ReviewReportStatus::Upheld) {
                return $report; // idempotent repeat — never re-applies the moderation action
            }

            $previous = $report->status;
            $report = $this->transition->execute($report, ReviewReportStatus::Upheld, [
                'reviewed_at' => now()->toImmutable()->utc(),
                'reviewed_by' => $admin->id,
                'resolution_reason' => $this->sanitizeReason($resolutionReason),
                'resolution_action' => $action,
            ]);

            $this->applyModerationAction($report, $admin, $resolutionReason, $action);
            $this->auditTransition($admin, 'review_report_upheld', $report, $previous, ReviewReportStatus::Upheld, $resolutionReason);
            ReviewReportUpheld::dispatch($report);

            return $report;
        });
    }

    public function dismiss(ReviewReport $report, User $admin, string $resolutionReason, ReviewReportResolutionAction $action = ReviewReportResolutionAction::NoAction): ReviewReport
    {
        $this->authorizeResolve($admin, $report);
        $this->assertResolutionReason($resolutionReason);
        $this->assertActionAllowed($action, self::DISMISS_ACTIONS, 'dismissed');

        return DB::transaction(function () use ($report, $admin, $resolutionReason, $action): ReviewReport {
            $report = $this->reports->lock($report);

            if ($report->status === ReviewReportStatus::Dismissed) {
                return $report;
            }

            $previous = $report->status;
            $report = $this->transition->execute($report, ReviewReportStatus::Dismissed, [
                'reviewed_at' => now()->toImmutable()->utc(),
                'reviewed_by' => $admin->id,
                'resolution_reason' => $this->sanitizeReason($resolutionReason),
                'resolution_action' => $action,
            ]);

            $this->applyModerationAction($report, $admin, $resolutionReason, $action);
            $this->auditTransition($admin, 'review_report_dismissed', $report, $previous, ReviewReportStatus::Dismissed, $resolutionReason);
            ReviewReportDismissed::dispatch($report);

            return $report;
        });
    }

    public function markDuplicate(ReviewReport $report, User $admin, ?string $resolutionReason = null): ReviewReport
    {
        $this->authorizeResolve($admin, $report);

        return DB::transaction(function () use ($report, $admin, $resolutionReason): ReviewReport {
            $report = $this->reports->lock($report);

            if ($report->status === ReviewReportStatus::Duplicate) {
                return $report;
            }

            $previous = $report->status;
            $report = $this->transition->execute($report, ReviewReportStatus::Duplicate, [
                'reviewed_at' => now()->toImmutable()->utc(),
                'reviewed_by' => $admin->id,
                'resolution_reason' => $this->sanitizeReason($resolutionReason),
                'resolution_action' => ReviewReportResolutionAction::NoAction,
            ]);

            $this->auditTransition($admin, 'review_report_marked_duplicate', $report, $previous, ReviewReportStatus::Duplicate, $resolutionReason);
            ReviewReportMarkedDuplicate::dispatch($report);

            return $report;
        });
    }

    public function markRemainingPendingAsDuplicate(LessonReview $review, User $admin, string $resolutionReason): int
    {
        $remaining = $this->reports->pendingOrUnderReviewForReview($review);
        $resolved = 0;

        foreach ($remaining as $report) {
            $this->markDuplicate($report, $admin, $resolutionReason);
            $resolved++;
        }

        return $resolved;
    }

    public function adminProjection(ReviewReport $report): ReviewReportAdminData
    {
        return ReviewReportAdminData::fromReport($report);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /** @throws AuthorizationException */
    private function authorizeReport(User $reporter, LessonReview $review): void
    {
        if (! $reporter->can('report', $review)) {
            throw new AuthorizationException('You may not report this review.');
        }
    }

    /**
     * The self-resolution exclusion is independently enforced here, not
     * only in ReviewReportPolicy — Gate::before grants super_admin a
     * global permission bypass that skips the policy entirely, and
     * Spatie roles are not mutually exclusive, so an account holding
     * both `instructor` and `super_admin` must still never resolve a
     * report about their own review.
     *
     * @throws AuthorizationException
     */
    private function authorizeResolve(User $admin, ReviewReport $report): void
    {
        if ($admin->id === $report->review->instructor_id) {
            throw new AuthorizationException('You may not resolve a report about your own review.');
        }

        if (! $admin->can('resolve', $report)) {
            throw new AuthorizationException('You may not resolve this report.');
        }
    }

    /** @throws ReviewValidationException */
    private function assertResolutionReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new ReviewValidationException('This resolution requires a reason.');
        }
    }

    /**
     * @param  list<ReviewReportResolutionAction>  $allowed
     *
     * @throws ReviewValidationException
     */
    private function assertActionAllowed(ReviewReportResolutionAction $action, array $allowed, string $outcome): void
    {
        if (! in_array($action, $allowed, strict: true)) {
            throw new ReviewValidationException(sprintf('"%s" is not a valid action when a report is %s.', $action->label(), $outcome));
        }
    }

    private function applyModerationAction(ReviewReport $report, User $admin, string $reason, ReviewReportResolutionAction $action): void
    {
        match ($action) {
            ReviewReportResolutionAction::HideReview => $this->moderation->hide($report->review, $admin, $reason),
            ReviewReportResolutionAction::RejectReview => $this->moderation->reject($report->review, $admin, $reason),
            ReviewReportResolutionAction::ArchiveReview => $this->moderation->archive($report->review, $admin, $reason),
            ReviewReportResolutionAction::RestoreReview => $this->moderation->restore($report->review, $admin, $reason),
            ReviewReportResolutionAction::NoAction => null,
        };
    }

    private function sanitizeReason(?string $reason): ?string
    {
        if ($reason === null || trim($reason) === '') {
            return null;
        }

        return mb_substr(trim(strip_tags($reason)), 0, 500);
    }

    private function auditTransition(User $admin, string $event, ReviewReport $report, ReviewReportStatus $previous, ReviewReportStatus $new, ?string $reason): void
    {
        $this->audit->logUser(
            $admin,
            self::LOG_NAME,
            $event,
            sprintf('Report %s: %s → %s.', $report->id, $previous->value, $new->value),
            $report,
            [
                'review_id' => $report->review_id,
                'previous_status' => $previous->value,
                'new_status' => $new->value,
                'reason' => $reason,
                'version' => $report->version,
            ],
        );
    }
}
