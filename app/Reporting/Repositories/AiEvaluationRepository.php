<?php

declare(strict_types=1);

namespace App\Reporting\Repositories;

use App\Ai\Enums\AiFeature;
use App\Ai\Enums\AiRunStatus;
use App\Ai\Evaluation\Enums\AiFeedbackAction;
use App\Reporting\ValueObjects\ReportingPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Every query behind the AI evaluation report.
 *
 * Lives in Reporting rather than in app/Ai deliberately: answering
 * "was this useful" means reading four feature tables, and the AI
 * module is forbidden from depending on business domains — that
 * independence is what keeps providers swappable. Reporting's whole job
 * is composing other domains' data, so the coupling belongs here.
 *
 * OUTCOMES ARE DERIVED, NEVER DUPLICATED. Each feature already records
 * what a human did with its output; this reads those columns rather
 * than mirroring them into an evaluation table that could drift.
 */
final class AiEvaluationRepository
{
    /**
     * Each feature's own definition of "a human took this seriously"
     * versus "a human rejected it". Deliberately explicit here rather
     * than inferred, because the four features mean genuinely different
     * things and a shared guess would quietly compare the wrong states.
     *
     * @return array<string, array{table: string, accepted: list<string>, rejected: list<string>, pending: list<string>, acceptedLabel: string, rejectedLabel: string}>
     */
    public static function outcomeMap(): array
    {
        return [
            AiFeature::QualityInsights->value => [
                'table' => 'ai_quality_insights',
                'accepted' => ['reviewed'],
                'rejected' => [],
                'pending' => ['pending', 'ready'],
                'acceptedLabel' => 'Reviewed',
                'rejectedLabel' => 'Rejected',
            ],
            AiFeature::HomeworkAssistant->value => [
                'table' => 'homework_ai_feedback_drafts',
                'accepted' => ['used'],
                'rejected' => ['discarded'],
                'pending' => ['pending', 'ready'],
                'acceptedLabel' => 'Used as a starting point',
                'rejectedLabel' => 'Discarded',
            ],
            AiFeature::LessonSummary->value => [
                'table' => 'lesson_ai_summaries',
                'accepted' => ['approved'],
                'rejected' => ['discarded'],
                'pending' => ['pending', 'ready'],
                'acceptedLabel' => 'Approved',
                'rejectedLabel' => 'Discarded',
            ],
            AiFeature::CommunicationModeration->value => [
                'table' => 'message_safety_findings',
                'accepted' => ['confirmed'],
                'rejected' => ['dismissed'],
                'pending' => ['open'],
                'acceptedLabel' => 'Confirmed',
                'rejectedLabel' => 'Dismissed',
            ],
        ];
    }

    /**
     * Run counters per feature from ai_runs.
     *
     * @return array<string, array{runs: int, succeeded: int, failed: int, rejected: int, blocked: int, input_tokens: int, output_tokens: int, cost: float}>
     */
    public function runTotalsByFeature(ReportingPeriod $period): array
    {
        $rows = DB::table('ai_runs')
            ->where('created_at', '>=', $period->startUtc)
            ->where('created_at', '<', $period->endUtcExclusive)
            ->selectRaw('feature_key, status, COUNT(*) as runs, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, SUM(estimated_cost) as cost')
            ->groupBy('feature_key', 'status')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $feature = (string) $row->feature_key;

            $totals[$feature] ??= [
                'runs' => 0, 'succeeded' => 0, 'failed' => 0, 'rejected' => 0, 'blocked' => 0,
                'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0.0,
            ];

            $totals[$feature]['runs'] += (int) $row->runs;
            $totals[$feature]['input_tokens'] += (int) $row->input_tokens;
            $totals[$feature]['output_tokens'] += (int) $row->output_tokens;
            $totals[$feature]['cost'] += (float) $row->cost;

            $bucket = match ((string) $row->status) {
                AiRunStatus::Succeeded->value => 'succeeded',
                AiRunStatus::Failed->value => 'failed',
                AiRunStatus::Rejected->value => 'rejected',
                AiRunStatus::Blocked->value => 'blocked',
                default => null,
            };

            if ($bucket !== null) {
                $totals[$feature][$bucket] += (int) $row->runs;
            }
        }

        return $totals;
    }

    /**
     * Median rather than mean latency: one 40-second timeout drags a
     * mean far enough to hide what the typical run actually costs a
     * waiting user.
     *
     * @return array<string, int> feature key => median latency ms
     */
    public function medianLatencyByFeature(ReportingPeriod $period): array
    {
        $rows = DB::table('ai_runs')
            ->where('created_at', '>=', $period->startUtc)
            ->where('created_at', '<', $period->endUtcExclusive)
            ->whereNotNull('latency_ms')
            ->orderBy('latency_ms')
            ->get(['feature_key', 'latency_ms']);

        $byFeature = [];

        foreach ($rows as $row) {
            $byFeature[(string) $row->feature_key][] = (int) $row->latency_ms;
        }

        $medians = [];

        foreach ($byFeature as $feature => $values) {
            $count = count($values);
            $middle = intdiv($count, 2);

            $medians[$feature] = $count % 2 === 1
                ? $values[$middle]
                : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
        }

        return $medians;
    }

    /**
     * Outcome counters read from each feature's OWN table.
     *
     * @return array<string, array<string, int>> feature key => status => count
     */
    public function outcomeCountsByFeature(ReportingPeriod $period): array
    {
        $counts = [];

        foreach (self::outcomeMap() as $feature => $config) {
            $rows = DB::table($config['table'])
                ->where('created_at', '>=', $period->startUtc)
                ->where('created_at', '<', $period->endUtcExclusive)
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->get();

            foreach ($rows as $row) {
                $counts[$feature][(string) $row->status] = (int) $row->total;
            }
        }

        return $counts;
    }

    /**
     * Explicit reviewer verdicts.
     *
     * @return array<string, array{helpful: int, not_helpful: int, reasons: array<string, int>}>
     */
    public function verdictsByFeature(ReportingPeriod $period): array
    {
        $rows = DB::table('ai_feedback_events')
            ->where('created_at', '>=', $period->startUtc)
            ->where('created_at', '<', $period->endUtcExclusive)
            ->selectRaw('feature_key, action, reason_code, COUNT(*) as total')
            ->groupBy('feature_key', 'action', 'reason_code')
            ->get();

        $verdicts = [];

        foreach ($rows as $row) {
            $feature = (string) $row->feature_key;
            $verdicts[$feature] ??= ['helpful' => 0, 'not_helpful' => 0, 'reasons' => []];

            $total = (int) $row->total;

            if ((string) $row->action === AiFeedbackAction::Helpful->value) {
                $verdicts[$feature]['helpful'] += $total;

                continue;
            }

            $verdicts[$feature]['not_helpful'] += $total;

            if ($row->reason_code !== null) {
                $reason = (string) $row->reason_code;
                $verdicts[$feature]['reasons'][$reason] = ($verdicts[$feature]['reasons'][$reason] ?? 0) + $total;
            }
        }

        return $verdicts;
    }

    /**
     * Per prompt version: runs and cost from ai_runs, verdicts from
     * feedback, and outcomes joined from the feature table that stores
     * the same prompt version on its own row.
     *
     * @return list<array<string, mixed>>
     */
    public function promptVersionTotals(ReportingPeriod $period): array
    {
        $runs = DB::table('ai_runs')
            ->where('created_at', '>=', $period->startUtc)
            ->where('created_at', '<', $period->endUtcExclusive)
            ->whereNotNull('prompt_key')
            ->selectRaw('prompt_key, prompt_version, COUNT(*) as runs, SUM(estimated_cost) as cost')
            ->groupBy('prompt_key', 'prompt_version')
            ->get();

        $verdicts = DB::table('ai_feedback_events')
            ->where('created_at', '>=', $period->startUtc)
            ->where('created_at', '<', $period->endUtcExclusive)
            ->whereNotNull('prompt_key')
            ->selectRaw('prompt_key, prompt_version, action, COUNT(*) as total')
            ->groupBy('prompt_key', 'prompt_version', 'action')
            ->get();

        $outcomes = $this->outcomesByPromptVersion($period);

        $result = [];

        foreach ($runs as $row) {
            $key = $row->prompt_key.':'.($row->prompt_version ?? '');

            $helpful = $verdicts->first(fn ($v): bool => $v->prompt_key === $row->prompt_key
                && $v->prompt_version === $row->prompt_version
                && $v->action === AiFeedbackAction::Helpful->value)?->total ?? 0;

            $notHelpful = $verdicts->first(fn ($v): bool => $v->prompt_key === $row->prompt_key
                && $v->prompt_version === $row->prompt_version
                && $v->action === AiFeedbackAction::NotHelpful->value)?->total ?? 0;

            $result[] = [
                'prompt_key' => (string) $row->prompt_key,
                'prompt_version' => (string) ($row->prompt_version ?? ''),
                'runs' => (int) $row->runs,
                'cost' => (float) $row->cost,
                'helpful' => (int) $helpful,
                'not_helpful' => (int) $notHelpful,
                'accepted' => $outcomes[$key]['accepted'] ?? 0,
                'rejected' => $outcomes[$key]['rejected'] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Outcomes grouped by the prompt version stored on each feature's
     * own row — which is why every feature persists prompt_key/version
     * alongside its result rather than relying on the run alone.
     *
     * @return array<string, array{accepted: int, rejected: int}>
     */
    private function outcomesByPromptVersion(ReportingPeriod $period): array
    {
        $totals = [];

        foreach (self::outcomeMap() as $config) {
            $rows = DB::table($config['table'])
                ->where('created_at', '>=', $period->startUtc)
                ->where('created_at', '<', $period->endUtcExclusive)
                ->whereNotNull('prompt_key')
                ->selectRaw('prompt_key, prompt_version, status, COUNT(*) as total')
                ->groupBy('prompt_key', 'prompt_version', 'status')
                ->get();

            foreach ($rows as $row) {
                $key = $row->prompt_key.':'.($row->prompt_version ?? '');
                $totals[$key] ??= ['accepted' => 0, 'rejected' => 0];

                if (in_array((string) $row->status, $config['accepted'], true)) {
                    $totals[$key]['accepted'] += (int) $row->total;
                } elseif (in_array((string) $row->status, $config['rejected'], true)) {
                    $totals[$key]['rejected'] += (int) $row->total;
                }
            }
        }

        return $totals;
    }
}
