<?php

declare(strict_types=1);

namespace App\Quality\Intelligence\Contracts;

use App\Models\AiQualityInsight;
use App\Models\User;

interface QualityInsightRepositoryInterface
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): AiQualityInsight;

    /** @param array<string, mixed> $attributes */
    public function update(AiQualityInsight $insight, array $attributes): AiQualityInsight;

    public function find(string $id): ?AiQualityInsight;

    /**
     * A still-running insight for the same instructor and period, if
     * one exists — the duplicate-spend guard behind
     * QualityInsightService::request().
     */
    public function pendingFor(User $instructor, string $periodPreset, string $periodStart, string $periodEnd): ?AiQualityInsight;
}
