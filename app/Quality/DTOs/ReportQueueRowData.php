<?php

declare(strict_types=1);

namespace App\Quality\DTOs;

use App\Models\ReviewReport;
use Carbon\CarbonImmutable;

/**
 * One row in the admin review-report queue. Reporter identity is
 * deliberately absent — the spec permits exposing it only to users
 * holding a specific inspection permission, and no dashboard queue
 * requires it to be actionable (the review reference and reason are
 * enough to triage). The full report record (with reporter id) is
 * still available through `ReviewReportService::adminProjection()`
 * for anyone who separately holds `View:ReviewReport`.
 */
final readonly class ReportQueueRowData
{
    public function __construct(
        public string $reportId,
        public string $reviewId,
        public int $instructorId,
        public string $instructorName,
        public string $reason,
        public string $status,
        public CarbonImmutable $submittedAt,
        public string $reviewStatus,
        public int $reportCountForReview,
    ) {}

    public static function fromReport(ReviewReport $report): self
    {
        $review = $report->review;

        return new self(
            reportId: $report->id,
            reviewId: $review->id,
            instructorId: $review->instructor_id,
            instructorName: $review->instructor->name,
            reason: $report->reason->value,
            status: $report->status->value,
            submittedAt: CarbonImmutable::instance($report->submitted_at),
            reviewStatus: $review->status->value,
            reportCountForReview: (int) ($review->reports_count ?? $review->reports()->count()),
        );
    }
}
