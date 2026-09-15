<?php

declare(strict_types=1);

namespace App\Booking\Assignment\Strategies;

use App\Booking\Contracts\AssignmentStrategyInterface;
use App\Booking\Contracts\TeacherScorerInterface;
use App\Booking\DTOs\AssignmentCriteriaData;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Default algorithm: weighted sum of all tagged scorers, highest wins.
 * Deterministic tie-break on lowest user id.
 */
final class BestScoreStrategy implements AssignmentStrategyInterface
{
    public const string KEY = 'best_score';

    /** @param iterable<TeacherScorerInterface> $scorers */
    public function __construct(
        private readonly iterable $scorers,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function assign(AssignmentCriteriaData $criteria, Collection $candidates): ?User
    {
        // The container hands tagged services over as a generator that
        // re-instantiates every scorer on each iteration; resolve them
        // once per assignment so a scorer may cache per criteria and so
        // N candidates do not cost N container resolutions per scorer.
        $scorers = is_array($this->scorers) ? $this->scorers : iterator_to_array($this->scorers, false);

        return $candidates
            ->sortBy->id
            ->sortByDesc(fn (User $teacher): float => $this->totalScore($scorers, $teacher, $criteria))
            ->first();
    }

    /** @param  list<TeacherScorerInterface>  $scorers */
    private function totalScore(array $scorers, User $teacher, AssignmentCriteriaData $criteria): float
    {
        $total = 0.0;

        foreach ($scorers as $scorer) {
            $total += $scorer->weight() * max(0.0, min(1.0, $scorer->score($teacher, $criteria)));
        }

        return $total;
    }
}
