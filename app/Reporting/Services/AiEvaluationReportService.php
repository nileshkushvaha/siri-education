<?php

declare(strict_types=1);

namespace App\Reporting\Services;

use App\Ai\Enums\AiFeature;
use App\Ai\Services\AiBudgetGuard;
use App\Models\User;
use App\Reporting\Contracts\AiEvaluationReportServiceInterface;
use App\Reporting\DTOs\Ai\AiEvaluationOverviewData;
use App\Reporting\DTOs\Ai\AiFeatureEvaluationRow;
use App\Reporting\DTOs\Ai\AiPromptVersionRow;
use App\Reporting\Repositories\AiEvaluationRepository;
use App\Reporting\ValueObjects\ReportingPeriod;
use App\Settings\AiSettings;
use App\Settings\FeatureSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Composes the AI evaluation view. Read-only: it never writes a run, a
 * feedback event, or a feature record.
 *
 * Gated on `Configure:AiPlatform` — the right that already means "you
 * operate the AI platform". No new permission was minted: whoever
 * configures the models and holds the budget is exactly who should see
 * whether any of it is working.
 */
final class AiEvaluationReportService implements AiEvaluationReportServiceInterface
{
    public function __construct(
        private readonly AiEvaluationRepository $repository,
        private readonly AiBudgetGuard $budget,
        private readonly AiSettings $settings,
        private readonly FeatureSettings $features,
    ) {}

    public function overview(User $user, ReportingPeriod $period): AiEvaluationOverviewData
    {
        if (! $this->canView($user)) {
            throw new AuthorizationException('You may not view AI evaluation reporting.');
        }

        $runTotals = $this->repository->runTotalsByFeature($period);
        $latencies = $this->repository->medianLatencyByFeature($period);
        $outcomes = $this->repository->outcomeCountsByFeature($period);
        $verdicts = $this->repository->verdictsByFeature($period);

        $features = [];

        foreach (AiFeature::cases() as $feature) {
            $key = $feature->value;
            $runs = $runTotals[$key] ?? null;
            $config = AiEvaluationRepository::outcomeMap()[$key] ?? null;

            // A feature with no runs and no outcomes in the period is
            // omitted rather than rendered as a row of zeros — an empty
            // row reads as "tried and failed", not "not used".
            if ($runs === null && ($outcomes[$key] ?? []) === []) {
                continue;
            }

            $features[] = new AiFeatureEvaluationRow(
                featureKey: $key,
                featureLabel: $feature->label(),
                runs: $runs['runs'] ?? 0,
                succeeded: $runs['succeeded'] ?? 0,
                failed: $runs['failed'] ?? 0,
                rejected: $runs['rejected'] ?? 0,
                blocked: $runs['blocked'] ?? 0,
                inputTokens: $runs['input_tokens'] ?? 0,
                outputTokens: $runs['output_tokens'] ?? 0,
                estimatedCost: round($runs['cost'] ?? 0.0, 6),
                medianLatencyMs: $latencies[$key] ?? null,
                awaitingHuman: $this->sumStatuses($outcomes[$key] ?? [], $config['pending'] ?? []),
                acceptedOutcomes: $this->sumStatuses($outcomes[$key] ?? [], $config['accepted'] ?? []),
                rejectedOutcomes: $this->sumStatuses($outcomes[$key] ?? [], $config['rejected'] ?? []),
                acceptedLabel: $config['acceptedLabel'] ?? 'Accepted',
                rejectedLabel: $config['rejectedLabel'] ?? 'Rejected',
                helpfulVerdicts: $verdicts[$key]['helpful'] ?? 0,
                notHelpfulVerdicts: $verdicts[$key]['not_helpful'] ?? 0,
                notHelpfulReasons: $verdicts[$key]['reasons'] ?? [],
            );
        }

        return new AiEvaluationOverviewData(
            periodLabel: $period->label,
            features: $features,
            promptVersions: $this->promptVersions($period),
            totalCost: round(array_sum(array_map(fn (AiFeatureEvaluationRow $row): float => $row->estimatedCost, $features)), 6),
            costCurrency: $this->settings->cost_currency,
            spentToday: $this->budget->spentToday(),
            spentThisMonth: $this->budget->spentThisMonth(),
            dailyLimit: $this->settings->daily_cost_limit,
            monthlyLimit: $this->settings->monthly_cost_limit,
            aiEnabled: $this->features->ai_enabled,
            enabledCapabilities: $this->enabledCapabilities(),
        );
    }

    public function canView(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        try {
            return $user->hasPermissionTo('Configure:AiPlatform');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /** @return list<AiPromptVersionRow> */
    private function promptVersions(ReportingPeriod $period): array
    {
        $rows = [];

        foreach ($this->repository->promptVersionTotals($period) as $row) {
            $rows[] = new AiPromptVersionRow(
                promptKey: $row['prompt_key'],
                promptVersion: $row['prompt_version'],
                runs: $row['runs'],
                estimatedCost: round($row['cost'], 6),
                helpfulVerdicts: $row['helpful'],
                notHelpfulVerdicts: $row['not_helpful'],
                acceptedOutcomes: $row['accepted'],
                rejectedOutcomes: $row['rejected'],
            );
        }

        // Grouped by key so two versions of the same prompt sit next to
        // each other — the only comparison that means anything.
        usort($rows, fn (AiPromptVersionRow $a, AiPromptVersionRow $b): int => [$a->promptKey, $a->promptVersion] <=> [$b->promptKey, $b->promptVersion]);

        return $rows;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  list<string>  $statuses
     */
    private function sumStatuses(array $counts, array $statuses): int
    {
        $total = 0;

        foreach ($statuses as $status) {
            $total += $counts[$status] ?? 0;
        }

        return $total;
    }

    /** @return list<string> */
    private function enabledCapabilities(): array
    {
        $enabled = [];

        foreach (AiFeature::cases() as $feature) {
            $flag = $feature->settingsFlag();

            if ($flag !== null && (bool) $this->settings->{$flag}) {
                $enabled[] = $feature->label();
            }
        }

        return $enabled;
    }
}
