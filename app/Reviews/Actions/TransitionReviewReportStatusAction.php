<?php

declare(strict_types=1);

namespace App\Reviews\Actions;

use App\Models\ReviewReport;
use App\Reviews\Enums\ReviewReportStatus;
use App\Reviews\Exceptions\InvalidReviewReportTransitionException;

/**
 * The one place a report's status is written. Guards every change
 * through ReviewReportStatus::canTransitionTo() — mirrors
 * TransitionReviewStatusAction. Callers are responsible for locking
 * the row and committing the surrounding transaction; this only
 * validates and fills.
 *
 * @throws InvalidReviewReportTransitionException
 */
final class TransitionReviewReportStatusAction
{
    /** @param array<string, mixed> $extra */
    public function execute(ReviewReport $report, ReviewReportStatus $next, array $extra = []): ReviewReport
    {
        if (! $report->status->canTransitionTo($next)) {
            throw InvalidReviewReportTransitionException::between($report->status, $next);
        }

        $report->fill([...$extra, 'status' => $next, 'version' => $report->version + 1])->save();

        return $report;
    }
}
