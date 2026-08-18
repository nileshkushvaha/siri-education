<?php

declare(strict_types=1);

namespace App\Quality\Intelligence\Services;

use App\Models\LessonReview;
use App\Models\User;
use App\Quality\Contracts\QualitySignalRepositoryInterface;
use App\Quality\Intelligence\DTOs\AnonymizedReviewExcerpt;
use App\Quality\Intelligence\DTOs\QualityInsightInput;
use App\Reporting\Repositories\InstructorPerformanceRepository;
use App\Reporting\ValueObjects\ReportingPeriod;
use App\Reviews\Contracts\InstructorRatingAggregateServiceInterface;
use App\Reviews\Contracts\LessonReviewRepositoryInterface;
use App\Reviews\DTOs\InstructorRatingSummaryData;
use Illuminate\Support\Collection;

/**
 * Composes what the model is allowed to see, entirely from figures
 * other domains already compute.
 *
 * NO METRIC IS INVENTED HERE. Lesson outcomes, unique students, booked
 * hours and booking mix come from InstructorPerformanceRepository —
 * the same queries behind the Instructor Performance report, so an
 * insight can never quietly disagree with the report an admin checks it
 * against. Ratings come from InstructorRatingAggregateService, tag
 * counts from LessonReviewRepository, review text from the Quality
 * domain's own signal repository. This class only labels and caps.
 *
 * It reads the reporting REPOSITORY rather than the report SERVICE on
 * purpose: the service is a report-screen boundary that also builds
 * drill-down URLs (no panel exists inside a queue worker) and demands
 * reporting permissions the requesting admin need not hold. Authorization
 * for generating an insight belongs to AiQualityInsightPolicy, checked
 * before dispatch — not re-derived here from a different permission set.
 */
final class QualityInsightInputBuilder
{
    /**
     * A hard ceiling on how much review text can ever leave the
     * platform for one insight. Newest first — recent behaviour is what
     * an admin is asking about — and small enough that an insight is a
     * summary of a sample, never a bulk export of student writing.
     */
    public const int MAX_EXCERPTS = 12;

    public function __construct(
        private readonly InstructorPerformanceRepository $performance,
        private readonly InstructorRatingAggregateServiceInterface $ratings,
        private readonly LessonReviewRepositoryInterface $reviews,
        private readonly QualitySignalRepositoryInterface $signals,
        private readonly QualityInsightAnonymizer $anonymizer,
    ) {}

    public function build(User $instructor, ReportingPeriod $period): QualityInsightInput
    {
        $id = (int) $instructor->id;
        $summary = $this->ratings->summaryFor($id);

        $reviews = $this->signals->publishedReviewsInWindow(
            instructorId: $id,
            from: $period->startUtc->toImmutable(),
            until: $period->endUtcExclusive->toImmutable(),
            // One over the cap so "more reviews than excerpts" is
            // detectable without a second count query.
            limit: self::MAX_EXCERPTS + 1,
        );

        return new QualityInsightInput(
            periodLabel: $period->label,
            statistics: $this->statistics($id, $period, $summary),
            dimensionRatings: $this->dimensionRatings($summary),
            tagCounts: $this->tagCounts($id),
            excerpts: $this->excerpts($reviews->take(self::MAX_EXCERPTS), $instructor),
            reviewsInPeriod: $reviews->count(),
        );
    }

    /** @return array<string, int|float|string> */
    private function statistics(int $id, ReportingPeriod $period, InstructorRatingSummaryData $summary): array
    {
        $ids = [$id];

        $bookings = $this->performance->bookingTypeCountsFor($ids, $period)[$id] ?? [];
        $outcomes = $this->performance->outcomeCountsFor($ids, $period)[$id] ?? [];
        $students = $this->performance->uniqueStudentsFor($ids, $period)[$id] ?? 0;
        $hours = $this->performance->bookedHoursFor($ids, $period)[$id] ?? 0.0;
        $alerts = $this->performance->activeQualityAlertsFor($ids)[$id] ?? 0;

        return [
            'Demo bookings' => (int) ($bookings['demo'] ?? 0),
            'Paid bookings' => (int) ($bookings['paid'] ?? 0),
            'Completed lessons' => (int) ($outcomes['completed'] ?? 0),
            'Instructor no-shows' => (int) ($outcomes['instructor_no_show'] ?? 0),
            'Student no-shows' => (int) ($outcomes['student_no_show'] ?? 0),
            'Unique students' => (int) $students,
            'Booked teaching hours' => (float) $hours,
            'Open quality alerts' => (int) $alerts,
            // All-time figures, labelled as such so the model does not
            // read a lifetime average as period behaviour.
            'Lifetime average rating' => $summary->averageRating === null
                ? 'no ratings yet'
                : number_format($summary->averageRating, 2),
            'Lifetime review count' => $summary->reviewCount,
        ];
    }

    /** @return array<string, string> */
    private function dimensionRatings(InstructorRatingSummaryData $summary): array
    {
        $labels = InstructorRatingSummaryData::dimensionLabels();
        $out = [];

        foreach ($summary->dimensionAverages as $key => $average) {
            if ($average === null) {
                continue;
            }

            $count = $summary->dimensionCounts[$key] ?? 0;

            // Sample size travels with every average: it is the single
            // most common way a quality figure gets over-read, and the
            // prompt instructs the model to weigh it.
            $out[$labels[$key] ?? $key] = sprintf('%s out of 5 (%d ratings)', number_format($average, 2), $count);
        }

        return $out;
    }

    /** @return array<string, int> */
    private function tagCounts(int $instructorId): array
    {
        $counts = [];

        foreach ($this->reviews->tagCountsForInstructor($instructorId) as $tag) {
            if (($tag['count'] ?? 0) > 0) {
                $counts[(string) $tag['label']] = (int) $tag['count'];
            }
        }

        return $counts;
    }

    /**
     * @param  Collection<int, LessonReview>  $reviews
     * @return list<AnonymizedReviewExcerpt>
     */
    private function excerpts(Collection $reviews, User $instructor): array
    {
        $reviews->loadMissing('student');

        $excerpts = [];
        $letter = 'A';

        foreach ($reviews as $review) {
            $text = $this->anonymizer->anonymize(
                $review->content,
                $this->anonymizer->identityHintsFor($review->student, $instructor),
            );

            $excerpts[] = new AnonymizedReviewExcerpt(
                label: 'Review '.$letter++,
                overallRating: (int) $review->overall_rating,
                text: $text,
                tags: $this->tagLabels($review),
            );
        }

        return $excerpts;
    }

    /** @return list<string> */
    private function tagLabels(LessonReview $review): array
    {
        $tags = $review->tags;

        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_map('strval', array_filter($tags, 'is_scalar')));
    }
}
