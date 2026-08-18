<?php

declare(strict_types=1);

namespace App\Quality\Intelligence\Prompts;

use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFeature;
use App\Ai\Enums\AiModelRole;
use App\Ai\Prompts\PromptDefinition;
use App\Quality\Intelligence\Schemas\QualityInsightSchema;

/**
 * `quality_insight:v1` — registered into the P0 prompt registry by
 * QualityIntelligenceServiceProvider.
 *
 * FROZEN. Changing any wording below means adding a v2 alongside it,
 * never editing this text: ai_runs rows recorded `quality_insight:v1`,
 * and that reference is only meaningful while the text behind it is
 * stable. The stored insight rows likewise say which version produced
 * them, so a later comparison of v1 against v2 is a real measurement.
 *
 * The system prompt does the safety work that no schema can: it forbids
 * verdicts, requires hedged language, requires sample size to be
 * weighed, and forbids recommending any consequence — pay, status,
 * ranking or discipline — because those are decisions the platform
 * reserves for humans and never derives from a model's opinion.
 */
final class QualityInsightPrompt
{
    public const string KEY = 'quality_insight';

    public const string VERSION = 'v1';

    private const string SYSTEM = <<<'PROMPT'
        You are assisting an administrator at an online tutoring platform. You analyse
        anonymized quality signals about ONE instructor and produce a short, cautious
        briefing that helps a human decide where to look next.

        You are advisory only. You are NOT making a decision, and nothing you write will
        be acted on automatically.

        Rules you must follow:
        - Never state a verdict about the instructor's competence, character, or future.
        - Never recommend suspension, dismissal, warnings, pay changes, ranking changes,
          promotion, or any consequence of any kind. Recommend only what a human should
          LOOK AT.
        - Weigh sample size explicitly. Two ratings are an anecdote, not a trend. Say so
          when the data is thin, and lower your confidence accordingly.
        - Distinguish clearly between what the data shows and what it might suggest. Use
          hedged language ("may indicate", "worth checking") for anything inferred.
        - Do not speculate about students, their identities, their circumstances, or
          anything outside the signals provided.
        - Do not invent numbers. Only reference figures present in the input.
        - If the signals are too sparse to support any observation, say that plainly in
          the summary, return empty lists, and give a low confidence value.

        Write for a busy administrator: specific, plain, and free of praise or blame.
        Respond only with the requested JSON object.
        PROMPT;

    private const string USER = <<<'PROMPT'
        Reporting period: {{ period_label }}

        Aggregate statistics for this instructor:
        {{ statistics }}

        Rating dimensions (lifetime averages, with sample sizes):
        {{ dimension_ratings }}

        Review tags selected by students (lifetime counts):
        {{ tag_counts }}

        Anonymized review excerpts from the period ({{ excerpt_note }}):
        {{ excerpts }}

        Produce:
        - summary: 2-4 sentences describing what these signals show for the period.
        - strengths: up to 5 specific, evidence-backed positives. Empty list if none are
          supported by the data.
        - concerns: up to 5 things an administrator may want to look into. These are
          questions to investigate, never findings. Empty list if none are supported.
        - recommended_review: what a human should examine next, or an empty string if
          nothing stands out.
        - confidence: 0 to 1, reflecting how much the available sample supports your
          observations.
        - requires_human_review: true whenever anything here would influence a decision
          about this instructor.
        PROMPT;

    public static function definition(): PromptDefinition
    {
        return new PromptDefinition(
            key: self::KEY,
            version: self::VERSION,
            feature: AiFeature::QualityInsights,
            capability: AiCapability::StructuredGeneration,
            systemTemplate: self::SYSTEM,
            userTemplate: self::USER,
            schemaKey: QualityInsightSchema::KEY,
            // The quality model, not the fast one: this is a low-volume,
            // human-reviewed analysis where reasoning quality matters far
            // more than per-run cost.
            modelRole: AiModelRole::Generation,
            maxOutputTokens: 1200,
            // Low but not zero — the task is analytical, and greedy
            // decoding on a summarization task tends toward repetition.
            temperature: 0.2,
        );
    }
}
