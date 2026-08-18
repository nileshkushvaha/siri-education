<?php

declare(strict_types=1);

namespace App\Quality\Intelligence\Contracts;

use App\Models\AiQualityInsight;
use App\Models\User;
use App\Quality\Intelligence\DTOs\QualityInsightData;
use App\Quality\Intelligence\Exceptions\QualityInsightException;
use App\Reporting\ValueObjects\ReportingPeriod;

/**
 * The admin quality-intelligence boundary. Every write to
 * ai_quality_insights goes through here — the Filament resource, the
 * queued result handler and any future caller included.
 */
interface QualityInsightServiceInterface
{
    /**
     * Creates a Pending insight and queues its run. Never calls a
     * provider synchronously.
     *
     * @throws QualityInsightException when AI is unavailable, the subject is not an instructor, or an identical run is already in flight
     */
    public function request(User $instructor, ReportingPeriod $period, User $requestedBy): AiQualityInsight;

    /** Stores validated AI output against a pending insight. Idempotent. */
    public function storeResult(AiQualityInsight $insight, QualityInsightData $data, ?string $aiRunId): AiQualityInsight;

    /** Records that a run produced nothing usable, with a stable, admin-readable reason. Idempotent. */
    public function markFailed(AiQualityInsight $insight, ?string $failureCode, ?string $aiRunId = null): AiQualityInsight;

    /** An administrator has read the insight and taken responsibility for what happens next. */
    public function markReviewed(AiQualityInsight $insight, User $reviewer, ?string $note = null): AiQualityInsight;
}
