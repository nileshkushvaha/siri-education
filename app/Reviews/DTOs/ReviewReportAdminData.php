<?php

declare(strict_types=1);

namespace App\Reviews\DTOs;

use App\Models\ReviewReport;
use Carbon\CarbonImmutable;

/**
 * Privacy-safe projection of one report for a future admin UI —
 * deliberately excludes student contact details, booking/payment
 * information, instructor compensation, raw audit payloads, and any
 * unrelated lesson data. The reporter reference is an id only (never
 * email/phone/name) and this DTO is only ever built from within an
 * already-permission-gated context (`View:ReviewReport`/
 * `ViewAny:ReviewReport`) — there is no unauthenticated or public path
 * to it.
 */
final readonly class ReviewReportAdminData
{
    public function __construct(
        public string $reportId,
        public string $reviewId,
        public string $reviewStatus,
        public int $reviewOverallRating,
        public ?string $reviewContentExcerpt,
        public string $reason,
        public ?string $explanation,
        public string $status,
        public int $reporterId,
        public CarbonImmutable $submittedAt,
        public ?CarbonImmutable $reviewedAt,
        public ?int $reviewedBy,
        public ?string $resolutionReason,
        public ?string $resolutionAction,
    ) {}

    public static function fromReport(ReviewReport $report): self
    {
        $review = $report->review;

        return new self(
            reportId: $report->id,
            reviewId: $review->id,
            reviewStatus: $review->status->value,
            reviewOverallRating: $review->overall_rating,
            reviewContentExcerpt: $review->content === null ? null : mb_substr($review->content, 0, 200),
            reason: $report->reason->value,
            explanation: $report->explanation,
            status: $report->status->value,
            reporterId: $report->reporter_id,
            submittedAt: CarbonImmutable::instance($report->submitted_at),
            reviewedAt: $report->reviewed_at === null ? null : CarbonImmutable::instance($report->reviewed_at),
            reviewedBy: $report->reviewed_by,
            resolutionReason: $report->resolution_reason,
            resolutionAction: $report->resolution_action?->value,
        );
    }
}
