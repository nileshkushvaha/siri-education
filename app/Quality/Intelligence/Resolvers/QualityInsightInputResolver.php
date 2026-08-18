<?php

declare(strict_types=1);

namespace App\Quality\Intelligence\Resolvers;

use App\Ai\Contracts\AiTaskInputResolverInterface;
use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\Enums\AiFailureCode;
use App\Ai\Exceptions\AiException;
use App\Models\AiQualityInsight;
use App\Quality\Intelligence\Contracts\QualityInsightRepositoryInterface;
use App\Quality\Intelligence\DTOs\AnonymizedReviewExcerpt;
use App\Quality\Intelligence\DTOs\QualityInsightInput;
use App\Quality\Intelligence\Services\QualityInsightInputBuilder;
use App\Quality\Intelligence\Services\QualityInsightService;
use App\Reporting\ValueObjects\ReportingPeriod;
use Carbon\CarbonImmutable;

/**
 * Turns a queued descriptor back into prompt variables — the point at
 * which platform data is read, and the only place it is rendered for a
 * model.
 *
 * Content is fetched HERE, at execution time, rather than being carried
 * in the queue payload (see AiTaskDescriptor): a job row is durable
 * storage, and review text belonging to students must not live in one.
 * A consequence worth stating plainly: if a review is deleted or
 * moderated away between clicking Generate and the job running, it is
 * simply not in the insight — which is the correct behaviour.
 *
 * Everything reaching the model has passed through
 * QualityInsightAnonymizer and is shaped as plain labelled text, never
 * a serialized record.
 */
final class QualityInsightInputResolver implements AiTaskInputResolverInterface
{
    public function __construct(
        private readonly QualityInsightRepositoryInterface $insights,
        private readonly QualityInsightInputBuilder $builder,
        private readonly QualityInsightService $service,
    ) {}

    public function resolve(AiTaskDescriptor $descriptor): array
    {
        $insight = $this->insight($descriptor);
        $instructor = $insight->instructor;

        if ($instructor === null) {
            throw new AiException('The instructor for this insight no longer exists.', AiFailureCode::NotConfigured);
        }

        $input = $this->builder->build($instructor, $this->period($insight));

        if ($input->isTooSparse()) {
            // Refusing costs nothing; asking a model to find patterns in
            // an empty period costs money and produces a confident
            // paragraph about nothing.
            throw new AiException('There is not enough activity in this period to analyse.', AiFailureCode::NotConfigured);
        }

        // Provenance is written before the call, so even a failed run
        // leaves a record of what would have been analysed.
        $this->service->recordProvenance($insight, $input->toProvenance());

        return [
            'period_label' => $input->periodLabel,
            'statistics' => $this->keyValueBlock($input->statistics),
            'dimension_ratings' => $this->keyValueBlock($input->dimensionRatings) ?: 'No dimension ratings recorded.',
            'tag_counts' => $this->keyValueBlock($input->tagCounts) ?: 'No review tags selected.',
            'excerpt_note' => $this->excerptNote($input),
            'excerpts' => $this->excerptBlock($input->excerpts),
        ];
    }

    private function insight(AiTaskDescriptor $descriptor): AiQualityInsight
    {
        $insight = $descriptor->correlationId === null
            ? null
            : $this->insights->find($descriptor->correlationId);

        if ($insight === null) {
            throw new AiException('The quality insight this run belongs to no longer exists.', AiFailureCode::NotConfigured);
        }

        return $insight;
    }

    /**
     * Rebuilt from the stored triple rather than carried in the payload,
     * so the period an insight reports on is always exactly the one
     * recorded on its row — including its timezone.
     */
    private function period(AiQualityInsight $insight): ReportingPeriod
    {
        return ReportingPeriod::custom(
            CarbonImmutable::parse($insight->period_start->toDateString()),
            CarbonImmutable::parse($insight->period_end->toDateString()),
            $insight->period_timezone,
        );
    }

    /** @param array<string, int|float|string> $values */
    private function keyValueBlock(array $values): string
    {
        $lines = [];

        foreach ($values as $label => $value) {
            $lines[] = sprintf('- %s: %s', $label, is_float($value) ? number_format($value, 1) : $value);
        }

        return implode("\n", $lines);
    }

    private function excerptNote(QualityInsightInput $input): string
    {
        $sent = count($input->excerpts);

        if ($input->reviewsInPeriod === 0) {
            return 'no published reviews in this period';
        }

        // The model is told the sample is partial; otherwise it would
        // reasonably treat 12 excerpts as the whole picture.
        return $sent >= $input->reviewsInPeriod
            ? sprintf('all %d published reviews in this period', $sent)
            : sprintf('the %d most recent of %d+ published reviews in this period', $sent, $input->reviewsInPeriod - 1);
    }

    /** @param list<AnonymizedReviewExcerpt> $excerpts */
    private function excerptBlock(array $excerpts): string
    {
        if ($excerpts === []) {
            return 'None.';
        }

        $lines = [];

        foreach ($excerpts as $excerpt) {
            $lines[] = sprintf(
                "%s — rated %d/5%s\n%s",
                $excerpt->label,
                $excerpt->overallRating,
                $excerpt->tags === [] ? '' : ' — tags: '.implode(', ', $excerpt->tags),
                $excerpt->text ?? '(no written comment)',
            );
        }

        return implode("\n\n", $lines);
    }
}
