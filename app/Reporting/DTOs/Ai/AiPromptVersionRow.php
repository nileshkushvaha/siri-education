<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Ai;

/**
 * One prompt version's measured performance — the comparison that
 * makes prompt revision an evidence-based change rather than a taste
 * argument.
 *
 * Comparable ONLY within a prompt key: acceptance rates for
 * `lesson_summary` and `homework_feedback` measure different human
 * decisions and must never be ranked against each other.
 */
final readonly class AiPromptVersionRow
{
    public function __construct(
        public string $promptKey,
        public string $promptVersion,
        public int $runs,
        public float $estimatedCost,
        public int $helpfulVerdicts,
        public int $notHelpfulVerdicts,
        public int $acceptedOutcomes,
        public int $rejectedOutcomes,
    ) {}

    public function acceptanceRate(): ?float
    {
        $decided = $this->acceptedOutcomes + $this->rejectedOutcomes;

        return $decided === 0 ? null : round($this->acceptedOutcomes / $decided, 4);
    }

    public function helpfulRate(): ?float
    {
        $verdicts = $this->helpfulVerdicts + $this->notHelpfulVerdicts;

        return $verdicts === 0 ? null : round($this->helpfulVerdicts / $verdicts, 4);
    }

    /**
     * Whether there is enough evidence to act on this row at all. A
     * version with four runs has no measured acceptance rate worth the
     * name, and presenting one invites a prompt change based on noise.
     */
    public function hasEnoughEvidence(int $minimumRuns = 20): bool
    {
        return $this->runs >= $minimumRuns;
    }
}
