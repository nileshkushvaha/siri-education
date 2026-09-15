<?php

declare(strict_types=1);

namespace App\Booking\Assignment\Scorers;

use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\TeacherScorerInterface;
use App\Booking\DTOs\AssignmentCriteriaData;
use App\Booking\Types\FreeDemoType;
use App\Models\User;
use WeakMap;

/**
 * Continuity: a student who lets the platform choose still meets a
 * familiar face. The instructor they had most recently for THIS subject
 * scores 1.0, any other instructor they have had before 0.5, everyone
 * else 0. Weighted so that, among instructors who are actually
 * available for the slot (availability is a hard filter before scoring),
 * the most recent one wins.
 *
 * Never for free demos: one free demo per instructor is a rule checked
 * after assignment, so steering a second demo back to the same
 * instructor would only make it fail.
 *
 * The student's history is read once per assignment and cached per
 * criteria instance — BestScoreStrategy asks every candidate.
 */
final class ContinuityScorer implements TeacherScorerInterface
{
    public const float MOST_RECENT_ON_SUBJECT = 1.0;

    public const float PREVIOUS_ANY_SUBJECT = 0.5;

    /** @var WeakMap<AssignmentCriteriaData, array<int, float>> */
    private WeakMap $history;

    public function __construct(private readonly BookingRepositoryInterface $bookings)
    {
        $this->history = new WeakMap;
    }

    public function score(User $teacher, AssignmentCriteriaData $criteria): float
    {
        if ($criteria->studentId === null || $criteria->typeKey === FreeDemoType::KEY) {
            return 0.0;
        }

        $scores = $this->history[$criteria] ??= $this->scoresFor($criteria);

        return $scores[$teacher->id] ?? 0.0;
    }

    public function weight(): float
    {
        // The other scorers can differ by at most 1.75 between two
        // candidates (workload 0.75, priority 0.5, timezone 0.5), so 2.0
        // lets the most recent instructor win whenever they are bookable.
        return 2.0;
    }

    /** @return array<int, float> instructor id => score */
    private function scoresFor(AssignmentCriteriaData $criteria): array
    {
        $scores = [];

        foreach ($this->bookings->previousInstructorIdsForStudent($criteria->studentId) as $instructorId) {
            $scores[$instructorId] = self::PREVIOUS_ANY_SUBJECT;
        }

        $mostRecentOnSubject = $this->bookings->previousInstructorIdsForStudent($criteria->studentId, $criteria->subject)->first();

        if ($mostRecentOnSubject !== null) {
            $scores[$mostRecentOnSubject] = self::MOST_RECENT_ON_SUBJECT;
        }

        return $scores;
    }
}
