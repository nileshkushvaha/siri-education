<?php

declare(strict_types=1);

namespace Tests\Unit\Booking;

use App\Booking\Assignment\Scorers\ContinuityScorer;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\DTOs\AssignmentCriteriaData;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

/**
 * Continuity: the instructor the student had last time for this subject
 * outranks everyone, any earlier instructor gets half credit, strangers
 * nothing — never for free demos, and read once per assignment.
 */
final class ContinuityScorerTest extends TestCase
{
    private function criteria(string $typeKey = 'paid_one_to_one', ?int $studentId = 42): AssignmentCriteriaData
    {
        return new AssignmentCriteriaData(
            typeKey: $typeKey,
            subject: 'maths',
            grade: 7,
            startsAt: CarbonImmutable::parse('2026-09-20 10:00:00'),
            durationMinutes: 60,
            studentId: $studentId,
        );
    }

    private function teacher(int $id): User
    {
        return (new User)->forceFill(['id' => $id]);
    }

    /** @param  list<int>  $all  @param  list<int>  $onSubject */
    private function scorer(array $all, array $onSubject, int &$reads = 0): ContinuityScorer
    {
        $bookings = Mockery::mock(BookingRepositoryInterface::class);
        $bookings->shouldReceive('previousInstructorIdsForStudent')
            ->andReturnUsing(function (int $studentId, ?string $subject = null) use ($all, $onSubject, &$reads): Collection {
                $reads++;

                return new Collection($subject === null ? $all : $onSubject);
            });

        return new ContinuityScorer($bookings);
    }

    public function test_most_recent_instructor_on_the_subject_scores_full_and_earlier_ones_half(): void
    {
        $scorer = $this->scorer(all: [7, 3, 9], onSubject: [3, 9]);
        $criteria = $this->criteria();

        $this->assertSame(1.0, $scorer->score($this->teacher(3), $criteria));
        $this->assertSame(0.5, $scorer->score($this->teacher(9), $criteria));
        $this->assertSame(0.5, $scorer->score($this->teacher(7), $criteria));
        $this->assertSame(0.0, $scorer->score($this->teacher(11), $criteria));
    }

    public function test_history_is_read_once_per_assignment_however_many_candidates(): void
    {
        $reads = 0;
        $scorer = $this->scorer([3], [3], $reads);
        $criteria = $this->criteria();

        foreach ([1, 2, 3, 4, 5] as $id) {
            $scorer->score($this->teacher($id), $criteria);
        }

        $this->assertSame(2, $reads, 'one read for all subjects, one for this subject — regardless of candidates');
    }

    public function test_no_student_means_no_continuity(): void
    {
        $reads = 0;
        $scorer = $this->scorer([3], [3], $reads);

        $this->assertSame(0.0, $scorer->score($this->teacher(3), $this->criteria(studentId: null)));
        $this->assertSame(0, $reads);
    }

    public function test_free_demos_never_get_continuity(): void
    {
        $reads = 0;
        $scorer = $this->scorer([3], [3], $reads);

        $this->assertSame(0.0, $scorer->score($this->teacher(3), $this->criteria(typeKey: 'free_demo')));
        $this->assertSame(0, $reads, 'one free demo per instructor would only fail afterwards');
    }

    public function test_weight_outranks_the_other_scorers_combined_spread(): void
    {
        // Workload (max delta 0.75) + priority (0.5) + timezone (0.5) = 1.75.
        $this->assertGreaterThan(1.75, (new ContinuityScorer(Mockery::mock(BookingRepositoryInterface::class)))->weight());
    }
}
