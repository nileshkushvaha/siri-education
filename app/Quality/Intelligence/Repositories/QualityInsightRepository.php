<?php

declare(strict_types=1);

namespace App\Quality\Intelligence\Repositories;

use App\Models\AiQualityInsight;
use App\Models\User;
use App\Quality\Intelligence\Contracts\QualityInsightRepositoryInterface;
use App\Quality\Intelligence\Enums\QualityInsightStatus;

final class QualityInsightRepository implements QualityInsightRepositoryInterface
{
    public function create(array $attributes): AiQualityInsight
    {
        return AiQualityInsight::query()->create($attributes);
    }

    public function update(AiQualityInsight $insight, array $attributes): AiQualityInsight
    {
        $insight->fill($attributes)->save();

        return $insight;
    }

    public function find(string $id): ?AiQualityInsight
    {
        return AiQualityInsight::query()->find($id);
    }

    public function pendingFor(User $instructor, string $periodPreset, string $periodStart, string $periodEnd): ?AiQualityInsight
    {
        return AiQualityInsight::query()
            ->where('instructor_id', $instructor->id)
            ->where('status', QualityInsightStatus::Pending)
            ->where('period_preset', $periodPreset)
            ->whereDate('period_start', $periodStart)
            ->whereDate('period_end', $periodEnd)
            ->first();
    }
}
