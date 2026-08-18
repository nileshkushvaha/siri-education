<?php

declare(strict_types=1);

namespace App\Reporting\Contracts;

use App\Models\User;
use App\Reporting\DTOs\Ai\AiEvaluationOverviewData;
use App\Reporting\ValueObjects\ReportingPeriod;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The read-only entry point for AI evaluation reporting. Composes
 * ai_runs telemetry, each feature's own outcome record, and reviewer
 * verdicts — and mutates nothing.
 */
interface AiEvaluationReportServiceInterface
{
    /** @throws AuthorizationException */
    public function overview(User $user, ReportingPeriod $period): AiEvaluationOverviewData;

    public function canView(?User $user): bool;
}
