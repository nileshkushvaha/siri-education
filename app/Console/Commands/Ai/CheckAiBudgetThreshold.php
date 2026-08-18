<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Ai\Services\AiBudgetGuard;
use App\Alerts\DTOs\OperationalAlertSignal;
use App\Alerts\Enums\OperationalAlertSeverity;
use App\Alerts\Enums\OperationalAlertType;
use App\Alerts\Services\OperationalAlertService;
use App\Settings\AiSettings;
use Illuminate\Console\Command;

/**
 * Warns before the AI budget guard starts silently blocking runs.
 *
 * A scheduled check rather than a hook inside AiBudgetGuard on purpose:
 * the guard runs before EVERY AI request, and adding alert bookkeeping
 * to that path would put a write on the hot path of every feature to
 * report a condition that changes over hours.
 *
 * Raises an existing OperationalAlert — no new alerting mechanism, no
 * new notification path. Deduplication, merging and the acknowledge/
 * resolve workflow all come from OperationalAlertService, so a budget
 * sitting at 85% for a week produces one alert with an occurrence
 * count, not a hundred.
 *
 * The alert carries percentages and amounts only. No feature, prompt or
 * content detail — a spend warning has no reason to name what the
 * platform was analysing.
 */
class CheckAiBudgetThreshold extends Command
{
    protected $signature = 'ai:check-budget';

    protected $description = 'Raise an operational alert when AI spend approaches its configured ceiling';

    public function handle(AiSettings $settings, AiBudgetGuard $budget, OperationalAlertService $alerts): int
    {
        $threshold = $settings->budget_alert_threshold;

        if ($threshold === null) {
            $this->info('AI budget alerting is disabled.');

            return self::SUCCESS;
        }

        $raised = 0;

        foreach ([
            ['label' => 'daily', 'limit' => $settings->daily_cost_limit, 'spent' => $budget->spentToday()],
            ['label' => 'monthly', 'limit' => $settings->monthly_cost_limit, 'spent' => $budget->spentThisMonth()],
        ] as $window) {
            $limit = $window['limit'];

            // A null limit is "unlimited" and a zero limit is a
            // deliberate stop — neither has a threshold to cross.
            if ($limit === null || $limit <= 0.0) {
                continue;
            }

            $ratio = $window['spent'] / $limit;

            if ($ratio < $threshold) {
                continue;
            }

            $alerts->createOrMerge(new OperationalAlertSignal(
                type: OperationalAlertType::AiBudgetThresholdReached,
                category: OperationalAlertType::AiBudgetThresholdReached->category(),
                // High only once the ceiling is actually reached and AI
                // has started refusing work; approaching it is a warning.
                severity: $ratio >= 1.0 ? OperationalAlertSeverity::High : OperationalAlertSeverity::Warning,
                title: sprintf('AI %s budget at %d%%', $window['label'], (int) round($ratio * 100)),
                summary: sprintf(
                    'Estimated AI spend for the %s window is %s of %s %s (%d%%). %s',
                    $window['label'],
                    number_format($window['spent'], 4),
                    number_format($limit, 2),
                    $settings->cost_currency,
                    (int) round($ratio * 100),
                    $ratio >= 1.0
                        ? 'AI requests are now being blocked until the ceiling is raised or the window resets.'
                        : 'Raise the ceiling or disable a capability before requests start being blocked.',
                ),
                metadata: [
                    'window' => $window['label'],
                    'ratio' => round($ratio, 4),
                    'limit' => round($limit, 2),
                    'currency' => $settings->cost_currency,
                ],
            ));

            $raised++;
        }

        $this->info($raised === 0 ? 'AI spend is within its alerting threshold.' : sprintf('Raised %d AI budget alert(s).', $raised));

        return self::SUCCESS;
    }
}
