<?php

declare(strict_types=1);

namespace App\Lessons\Summaries\Prompts;

use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFeature;
use App\Ai\Enums\AiModelRole;
use App\Ai\Prompts\PromptDefinition;
use App\Lessons\Summaries\Schemas\LessonSummarySchema;

/**
 * `lesson_summary:v1` — registered into the P0 prompt registry by
 * LessonServiceProvider.
 *
 * FROZEN. New wording means a v2 registered alongside it.
 *
 * The system prompt exists to stop the single most likely failure of
 * this feature: a model handed three sparse notes writing a fluent,
 * confident paragraph about a lesson that did not happen that way. It
 * is instructed to summarize ONLY what it was given, to leave lists
 * empty rather than fill them, and to keep suggestions visibly separate
 * from what the instructor actually reported.
 */
final class LessonSummaryPrompt
{
    public const string KEY = 'lesson_summary';

    public const string VERSION = 'v1';

    private const string SYSTEM = <<<'PROMPT'
        You help a tutor write up a lesson that has just finished. You produce a DRAFT
        for that tutor to edit and approve. You were not present at the lesson: every
        fact you have is in the input below.

        Rules you must follow:
        - Summarize ONLY what the input states. Never add plausible detail, never
          describe activities that were not mentioned, and never fill a gap with what
          usually happens in such a lesson.
        - Never claim the student has mastered, understood, or learned something unless
          the tutor's own note says so. "Practised factorisation" is not "understands
          factorisation".
        - Never assign a level, score, grade, percentage, or judgement of ability, and
          never imply progress toward one.
        - Keep facts and suggestions clearly separate. topics_covered and
          strengths_observed describe what the input reports. practice_recommendations
          and next_focus are your suggestions for the tutor to consider.
        - Return an EMPTY list rather than a weak or invented entry. A short, accurate
          summary is worth far more than a complete-looking one.
        - Write in plain, professional language a tutor could publish with light
          editing. Do not address the student directly.
        - If the input is too thin to describe the lesson, say exactly that in
          lesson_summary, return empty lists, and give a low confidence value.

        Respond only with the requested JSON object.
        PROMPT;

    private const string USER = <<<'PROMPT'
        Subject: {{ subject }}
        Academic level: {{ academic_level }}
        Topic: {{ topic }}
        Lesson length: {{ duration }} minutes

        Learning plan focus: {{ plan_focus }}
        Current plan objectives:
        {{ plan_objectives }}

        Tutor's own notes from this lesson:
        {{ instructor_notes }}

        Homework set during or after this lesson:
        {{ homework }}

        Produce:
        - lesson_summary: 2-4 sentences describing what this lesson covered, based only
          on the information above.
        - topics_covered: the specific topics the input shows were worked on. Empty list
          if the input does not name any.
        - strengths_observed: only what the tutor's notes actually report going well.
          Empty list if the notes report none.
        - practice_recommendations: what the student could practise before the next
          lesson. Your suggestion, clearly a suggestion.
        - next_focus: what the next lesson could cover. Your suggestion.
        - confidence: 0 to 1, reflecting how much the input actually supports your
          summary. Be honest — thin input means low confidence.
        - requires_instructor_review: always true. A tutor approves every summary.
        PROMPT;

    public static function definition(): PromptDefinition
    {
        return new PromptDefinition(
            key: self::KEY,
            version: self::VERSION,
            feature: AiFeature::LessonSummary,
            capability: AiCapability::StructuredGeneration,
            systemTemplate: self::SYSTEM,
            userTemplate: self::USER,
            schemaKey: LessonSummarySchema::KEY,
            modelRole: AiModelRole::Generation,
            maxOutputTokens: 1400,
            // Low: this is documentation of something that happened, and
            // invention is the failure mode that matters most here.
            temperature: 0.2,
        );
    }
}
