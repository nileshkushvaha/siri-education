<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Ai;

/**
 * One AI feature's evaluation for a period.
 *
 * Every figure has a stated source, because a metric nobody can trace
 * is a metric nobody should act on. Run counters come from `ai_runs`;
 * outcome counters come from the feature's OWN table and its own
 * definition of what a good outcome is; verdicts come from
 * `ai_feedback_events`.
 *
 * `acceptedOutcomes` / `rejectedOutcomes` deliberately mean different
 * things per feature — a used draft, an approved summary, a confirmed
 * finding — because "did a human take this seriously" is the only
 * comparable question across four features that do very different jobs.
 * The label pair is carried on the row so a dashboard never has to
 * guess.
 */
final readonly class AiFeatureEvaluationRow
{
    public function __construct(
        public string $featureKey,
        public string $featureLabel,
        // ── From ai_runs ──────────────────────────────────────────────
        public int $runs,
        public int $succeeded,
        public int $failed,
        public int $rejected,
        public int $blocked,
        public int $inputTokens,
        public int $outputTokens,
        public float $estimatedCost,
        public ?int $medianLatencyMs,
        // ── From the feature's own table ─────────────────────────────
        public int $awaitingHuman,
        public int $acceptedOutcomes,
        public int $rejectedOutcomes,
        public string $acceptedLabel,
        public string $rejectedLabel,
        // ── From ai_feedback_events ──────────────────────────────────
        public int $helpfulVerdicts,
        public int $notHelpfulVerdicts,
        /** @var array<string, int> reason code => count */
        public array $notHelpfulReasons,
    ) {}

    /**
     * Of the outcomes a human actually decided on, how many they took.
     * Null when nobody has decided yet — a zero here would read as
     * "nobody found it useful" when it means "nobody has looked".
     */
    public function acceptanceRate(): ?float
    {
        $decided = $this->acceptedOutcomes + $this->rejectedOutcomes;

        return $decided === 0 ? null : round($this->acceptedOutcomes / $decided, 4);
    }

    /** Of reviewers who gave an explicit verdict, how many found it worth their time. */
    public function helpfulRate(): ?float
    {
        $verdicts = $this->helpfulVerdicts + $this->notHelpfulVerdicts;

        return $verdicts === 0 ? null : round($this->helpfulVerdicts / $verdicts, 4);
    }

    /**
     * THE NUMBER THAT DECIDES WHETHER A FEATURE SURVIVES. Total spend
     * divided by outcomes a human actually took — not by runs, because
     * a cheap feature nobody accepts is not cheap, it is waste.
     */
    public function costPerAcceptedOutcome(): ?float
    {
        return $this->acceptedOutcomes === 0
            ? null
            : round($this->estimatedCost / $this->acceptedOutcomes, 6);
    }

    public function failureRate(): ?float
    {
        return $this->runs === 0 ? null : round(($this->failed + $this->rejected) / $this->runs, 4);
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
