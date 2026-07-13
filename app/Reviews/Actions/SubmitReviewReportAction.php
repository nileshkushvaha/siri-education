<?php

declare(strict_types=1);

namespace App\Reviews\Actions;

use App\Models\LessonReview;
use App\Models\ReviewReport;
use App\Models\User;
use App\Reviews\Contracts\LessonReviewRepositoryInterface;
use App\Reviews\Contracts\ReviewReportRepositoryInterface;
use App\Reviews\DTOs\SubmitReviewReportData;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Enums\ReviewReportStatus;
use App\Reviews\Enums\StudentReviewStatus;
use App\Reviews\Events\ReviewReported;
use App\Reviews\Exceptions\DuplicateReviewReportException;
use App\Reviews\Exceptions\ReviewNotReportableException;
use App\Reviews\Support\ReviewContentSanitizer;
use App\Services\AuditTrailService;
use App\Settings\ReviewSettings;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of new `review_reports` rows. Authorization is the
 * caller's job (ReviewReportService checks the LessonReviewPolicy
 * `report` ability before calling this); this action assumes the
 * acting user is already authorized and focuses purely on: lock the
 * review → revalidate it's actually reportable → reject a duplicate
 * active report → sanitize the explanation → create → audit → dispatch,
 * all in one transaction.
 */
final class SubmitReviewReportAction
{
    private const string LOG_NAME = 'reviews';

    public function __construct(
        private readonly LessonReviewRepositoryInterface $reviews,
        private readonly ReviewReportRepositoryInterface $reports,
        private readonly AuditTrailService $audit,
        private readonly ReviewSettings $settings,
    ) {}

    /**
     * @throws ReviewNotReportableException
     * @throws DuplicateReviewReportException
     */
    public function execute(LessonReview $review, User $reporter, SubmitReviewReportData $data): ReviewReport
    {
        return DB::transaction(function () use ($review, $reporter, $data): ReviewReport {
            $review = $this->reviews->lock($review);

            if (! $this->settings->review_reporting_enabled) {
                throw ReviewNotReportableException::reportingDisabled();
            }

            if ($review->status !== StudentReviewStatus::Published || $review->review_mode !== LessonReviewEligibilityMode::PublicReview) {
                throw ReviewNotReportableException::forReview($review);
            }

            if ($this->reports->findActiveForReporter($review, $reporter, $data->reason) !== null) {
                throw DuplicateReviewReportException::forReview($review, $data->reason);
            }

            $sanitized = ReviewContentSanitizer::sanitize($data->explanation);
            $explanation = $sanitized->content === null ? null : mb_substr($sanitized->content, 0, 1000);

            $report = $this->reports->create([
                'review_id' => $review->id,
                'reporter_id' => $reporter->id,
                'reason' => $data->reason,
                'explanation' => $explanation,
                'status' => ReviewReportStatus::Pending,
                'submitted_at' => now()->toImmutable()->utc(),
                'version' => 1,
            ]);

            // Never the raw explanation — only ids, reason, and flag
            // categories reach the audit trail (same principle as
            // review-submission audit properties).
            $this->audit->logUser(
                $reporter,
                self::LOG_NAME,
                'review_reported',
                sprintf('Review %s reported for "%s".', $review->id, $data->reason->value),
                $report,
                [
                    'review_id' => $review->id,
                    'reason' => $data->reason->value,
                    'flags' => array_map(fn ($f) => $f->value, $sanitized->flags),
                ],
            );

            ReviewReported::dispatch($report);

            return $report;
        });
    }
}
