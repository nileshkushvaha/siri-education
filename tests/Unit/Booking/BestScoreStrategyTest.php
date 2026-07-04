<?php

declare(strict_types=1);

namespace Tests\Unit\Booking;

use App\Booking\Assignment\Strategies\BestScoreStrategy;
use App\Booking\Contracts\TeacherScorerInterface;
use App\Booking\DTOs\AssignmentCriteriaData;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Tests\TestCase;

class BestScoreStrategyTest extends TestCase
{
    private function criteria(): AssignmentCriteriaData
    {
        return new AssignmentCriteriaData(
            typeKey: 'free_demo',
            subject: 'maths',
            grade: 5,
            startsAt: CarbonImmutable::parse('2026-08-03 10:00:00'),
            durationMinutes: 30,
        );
    }

    private function teacher(int $id): User
    {
        return (new User)->forceFill(['id' => $id]);
    }

    private function scorer(callable $score, float $weight = 1.0): TeacherScorerInterface
    {
        return new class($score, $weight) implements TeacherScorerInterface
        {
            public function __construct(private $callback, private readonly float $weight) {}

            public function score(User $teacher, AssignmentCriteriaData $criteria): float
            {
                return ($this->callback)($teacher);
            }

            public function weight(): float
            {
                return $this->weight;
            }
        };
    }

    public function test_picks_highest_weighted_sum(): void
    {
        $strategy = new BestScoreStrategy([
            $this->scorer(fn (User $t): float => $t->id === 2 ? 1.0 : 0.2),
            $this->scorer(fn (User $t): float => 0.5, weight: 0.5),
        ]);

        $winner = $strategy->assign($this->criteria(), new Collection([
            $this->teacher(1), $this->teacher(2), $this->teacher(3),
        ]));

        $this->assertSame(2, $winner->id);
    }

    public function test_weights_change_the_outcome(): void
    {
        $candidates = new Collection([$this->teacher(1), $this->teacher(2)]);
        $scoreA = fn (User $t): float => $t->id === 1 ? 1.0 : 0.0;
        $scoreB = fn (User $t): float => $t->id === 2 ? 0.6 : 0.0;

        $lowWeightB = new BestScoreStrategy([$this->scorer($scoreA), $this->scorer($scoreB)]);
        $highWeightB = new BestScoreStrategy([$this->scorer($scoreA), $this->scorer($scoreB, weight: 2.0)]);

        $this->assertSame(1, $lowWeightB->assign($this->criteria(), $candidates)->id);
        $this->assertSame(2, $highWeightB->assign($this->criteria(), $candidates)->id);
    }

    public function test_scores_are_clamped_to_unit_interval(): void
    {
        $strategy = new BestScoreStrategy([
            $this->scorer(fn (User $t): float => $t->id === 1 ? 99.0 : 1.0), // clamped to 1.0
            $this->scorer(fn (User $t): float => $t->id === 2 ? 0.5 : 0.0),
        ]);

        $winner = $strategy->assign($this->criteria(), new Collection([
            $this->teacher(1), $this->teacher(2),
        ]));

        $this->assertSame(2, $winner->id);
    }

    public function test_tie_breaks_on_lowest_id(): void
    {
        $strategy = new BestScoreStrategy([$this->scorer(fn (): float => 0.5)]);

        $winner = $strategy->assign($this->criteria(), new Collection([
            $this->teacher(7), $this->teacher(3), $this->teacher(9),
        ]));

        $this->assertSame(3, $winner->id);
    }

    public function test_returns_null_for_no_candidates(): void
    {
        $strategy = new BestScoreStrategy([]);

        $this->assertNull($strategy->assign($this->criteria(), new Collection));
    }
}
