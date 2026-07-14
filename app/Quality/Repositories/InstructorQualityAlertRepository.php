<?php

declare(strict_types=1);

namespace App\Quality\Repositories;

use App\Models\InstructorQualityAlert;
use App\Quality\Contracts\InstructorQualityAlertRepositoryInterface;
use App\Quality\Enums\InstructorQualityAlertStatus;
use App\Quality\Enums\InstructorQualityAlertType;
use App\Quality\Enums\QualityAlertSourceType;

final class InstructorQualityAlertRepository implements InstructorQualityAlertRepositoryInterface
{
    public function create(array $attributes): InstructorQualityAlert
    {
        return InstructorQualityAlert::query()->create($attributes);
    }

    public function lock(InstructorQualityAlert $alert): InstructorQualityAlert
    {
        return InstructorQualityAlert::query()
            ->whereKey($alert->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function findByFingerprint(string $fingerprint): ?InstructorQualityAlert
    {
        return InstructorQualityAlert::query()->where('detection_fingerprint', $fingerprint)->first();
    }

    public function countTerminalForInstructorAndType(int $instructorId, InstructorQualityAlertType $type): int
    {
        return InstructorQualityAlert::query()
            ->where('instructor_id', $instructorId)
            ->where('alert_type', $type)
            ->whereIn('status', [
                InstructorQualityAlertStatus::Resolved,
                InstructorQualityAlertStatus::Dismissed,
                InstructorQualityAlertStatus::Duplicate,
                InstructorQualityAlertStatus::Expired,
            ])
            ->count();
    }

    public function findActiveForSource(QualityAlertSourceType $sourceType, string $sourceId): ?InstructorQualityAlert
    {
        return InstructorQualityAlert::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereIn('status', [InstructorQualityAlertStatus::Open, InstructorQualityAlertStatus::UnderReview])
            ->first();
    }
}
