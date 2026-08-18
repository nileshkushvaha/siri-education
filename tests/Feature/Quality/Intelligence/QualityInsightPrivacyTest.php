<?php

declare(strict_types=1);

namespace Tests\Feature\Quality\Intelligence;

use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFeature;
use App\Ai\Jobs\ExecuteAiTaskJob;
use App\Models\AiQualityInsight;
use App\Quality\Intelligence\Contracts\QualityInsightServiceInterface;
use App\Quality\Intelligence\Resolvers\QualityInsightInputResolver;
use App\Quality\Intelligence\Services\QualityInsightAnonymizer;
use App\Quality\Intelligence\Services\QualityInsightInputBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Quality\Intelligence\Concerns\BuildsQualityInsightFixtures;
use Tests\TestCase;

/**
 * The privacy boundary: exactly what may leave the platform for an AI
 * quality insight, and what must never.
 *
 * These assertions run against the REAL prompt variables the resolver
 * produces — the same strings the provider would receive — rather than
 * against the anonymizer in isolation, so a future change that adds a
 * field to the prompt is caught here.
 */
class QualityInsightPrivacyTest extends TestCase
{
    use BuildsQualityInsightFixtures, RefreshDatabase;

    /** @return array<string, string> the exact variables that would be sent */
    private function promptVariables(AiQualityInsight $insight): array
    {
        return app(QualityInsightInputResolver::class)->resolve(new AiTaskDescriptor(
            feature: AiFeature::QualityInsights,
            capability: AiCapability::StructuredGeneration,
            promptKey: 'quality_insight',
            inputResolver: QualityInsightInputResolver::class,
            correlationId: $insight->getKey(),
        ));
    }

    private function sentText(AiQualityInsight $insight): string
    {
        return implode("\n", $this->promptVariables($insight));
    }

    public function test_student_names_are_removed_from_review_text(): void
    {
        $this->enableQualityInsights();

        $instructor = $this->instructor('Priya', 'Nair');
        $student = $this->student('Mira', 'Kowalski');

        $this->publishedReview($instructor, $student, 5, 'Mira loved the lesson and Kowalski family will book again with Priya.');

        $insight = app(QualityInsightServiceInterface::class)->request($instructor, $this->period(), $this->admin());

        $sent = $this->sentText($insight);

        $this->assertStringNotContainsString('Mira', $sent);
        $this->assertStringNotContainsString('Kowalski', $sent);
        // The instructor's own name is removed too — it adds nothing
        // analytically and the model is already told whose data it is.
        $this->assertStringNotContainsString('Priya', $sent);
        // The substance of the review survives.
        $this->assertStringContainsString('loved the lesson', $sent);
    }

    public function test_contact_details_never_reach_the_prompt(): void
    {
        $this->enableQualityInsights();

        $instructor = $this->instructor();
        $student = $this->student();

        $this->publishedReview(
            $instructor,
            $student,
            4,
            'Contact me at parent@example.com or +44 7700 900123, my handle is @miraK, see https://example.com/tutor',
        );

        $insight = app(QualityInsightServiceInterface::class)->request($instructor, $this->period(), $this->admin());
        $sent = $this->sentText($insight);

        $this->assertStringNotContainsString('parent@example.com', $sent);
        $this->assertStringNotContainsString('7700', $sent);
        $this->assertStringNotContainsString('@miraK', $sent);
        $this->assertStringNotContainsString('example.com', $sent);
    }

    public function test_no_identifier_of_any_kind_reaches_the_prompt(): void
    {
        $this->enableQualityInsights();

        $instructor = $this->instructor();
        $student = $this->student();
        $review = $this->publishedReview($instructor, $student, 5, 'Very well prepared each week.');

        $insight = app(QualityInsightServiceInterface::class)->request($instructor, $this->period(), $this->admin());
        $sent = $this->sentText($insight);

        $this->assertStringNotContainsString((string) $student->id, $sent);
        $this->assertStringNotContainsString((string) $student->email, $sent);
        $this->assertStringNotContainsString($review->id, $sent);
        $this->assertStringNotContainsString($review->booking_id, $sent);
        $this->assertStringNotContainsString($review->lesson_id, $sent);
        $this->assertStringNotContainsString($insight->getKey(), $sent);
    }

    public function test_reviews_are_labelled_positionally_rather_than_identified(): void
    {
        $this->enableQualityInsights();

        $instructor = $this->instructor();
        $this->publishedReview($instructor, $this->student(), 5, 'Clear explanations.');
        $this->publishedReview($instructor, $this->student('Tom', 'Baker'), 3, 'Sometimes rushed.');

        $insight = app(QualityInsightServiceInterface::class)->request($instructor, $this->period(), $this->admin());
        $sent = $this->sentText($insight);

        $this->assertStringContainsString('Review A', $sent);
        $this->assertStringContainsString('Review B', $sent);
    }

    /** A hard cap on how much student writing can ever leave in one insight. */
    public function test_the_number_of_excerpts_is_capped(): void
    {
        $this->enableQualityInsights();

        $instructor = $this->instructor();

        foreach (range(1, QualityInsightInputBuilder::MAX_EXCERPTS + 5) as $i) {
            $this->publishedReview($instructor, $this->student("Student{$i}", 'Test'), 5, "Comment number {$i} about the lesson.");
        }

        $insight = app(QualityInsightServiceInterface::class)->request($instructor, $this->period(), $this->admin());
        $variables = $this->promptVariables($insight);

        $this->assertSame(
            QualityInsightInputBuilder::MAX_EXCERPTS,
            substr_count($variables['excerpts'], ' — rated '),
        );
        // The model is told the sample is partial, so it cannot read
        // twelve excerpts as the complete picture.
        $this->assertStringContainsString('most recent of', $variables['excerpt_note']);
    }

    public function test_each_excerpt_is_length_capped(): void
    {
        $this->enableQualityInsights();

        $instructor = $this->instructor();
        $this->publishedReview($instructor, $this->student(), 5, str_repeat('detail about the lesson ', 200));

        $insight = app(QualityInsightServiceInterface::class)->request($instructor, $this->period(), $this->admin());
        $variables = $this->promptVariables($insight);

        $this->assertLessThanOrEqual(
            QualityInsightAnonymizer::MAX_EXCERPT_CHARACTERS + 10,
            mb_strlen(trim(explode("\n", $variables['excerpts'])[1] ?? '')),
        );
    }

    public function test_the_stored_provenance_snapshot_contains_no_review_text(): void
    {
        $this->enableQualityInsights();

        $instructor = $this->instructor();
        $this->publishedReview($instructor, $this->student(), 5, 'A very distinctive sentence about pacing.');

        $insight = app(QualityInsightServiceInterface::class)->request($instructor, $this->period(), $this->admin());
        $this->promptVariables($insight);

        $snapshot = json_encode($insight->refresh()->source_snapshot, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('distinctive sentence', $snapshot);
        $this->assertStringContainsString('reviews_in_period', $snapshot);
        $this->assertStringContainsString('Completed lessons', $snapshot);
    }

    public function test_the_queued_payload_carries_no_content(): void
    {
        $this->enableQualityInsights();

        $instructor = $this->instructor();
        $this->publishedReview($instructor, $this->student(), 5, 'A very distinctive sentence about pacing.');

        $insight = app(QualityInsightServiceInterface::class)->request($instructor, $this->period(), $this->admin());

        $payload = serialize(new ExecuteAiTaskJob(new AiTaskDescriptor(
            feature: AiFeature::QualityInsights,
            capability: AiCapability::StructuredGeneration,
            promptKey: 'quality_insight',
            inputResolver: QualityInsightInputResolver::class,
            correlationId: $insight->getKey(),
        )));

        $this->assertStringNotContainsString('distinctive sentence', $payload);
    }

    /** The anonymizer must not mangle ordinary words that happen to contain a short name. */
    public function test_name_redaction_respects_word_boundaries(): void
    {
        $anonymizer = app(QualityInsightAnonymizer::class);

        $result = $anonymizer->anonymize('Ann was fine but the planning was rushed.', ['Ann']);

        $this->assertStringNotContainsString('Ann was', (string) $result);
        $this->assertStringContainsString('planning', (string) $result);
    }
}
