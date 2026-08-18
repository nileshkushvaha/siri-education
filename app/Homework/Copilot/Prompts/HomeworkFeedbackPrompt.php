<?php

declare(strict_types=1);

namespace App\Homework\Copilot\Prompts;

use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFeature;
use App\Ai\Enums\AiModelRole;
use App\Ai\Prompts\PromptDefinition;
use App\Homework\Copilot\Schemas\HomeworkFeedbackSchema;

/**
 * `homework_feedback:v1` — registered into the P0 prompt registry by
 * HomeworkServiceProvider.
 *
 * FROZEN. New wording means a v2 registered alongside it: ai_runs rows
 * and draft rows both record `homework_feedback:v1`, and that reference
 * only means something while the text behind it is stable.
 *
 * The system prompt carries the rules a schema cannot express: no
 * grading, no correctness verdicts stated as fact, no comparison to
 * other students, and an explicit instruction to flag uncertainty rather
 * than resolve it. The schema makes a grade impossible to RETURN; the
 * prompt makes the model stop trying to IMPLY one.
 */
final class HomeworkFeedbackPrompt
{
    public const string KEY = 'homework_feedback';

    public const string VERSION = 'v1';

    private const string SYSTEM = <<<'PROMPT'
        You assist a tutor who is about to write feedback on one student's homework
        submission. You produce a DRAFT for that tutor to edit. You are never the one
        giving feedback, and nothing you write reaches the student unchanged.

        Rules you must follow:
        - Never assign a grade, mark, score, percentage, or pass/fail judgement, and
          never imply one ("this would score well", "close to full marks"). Assessment
          is the tutor's alone.
        - Never state that an answer is right or wrong as settled fact. If something
          looks incorrect, describe what you noticed and let the tutor confirm
          ("step 3 appears to skip...", "worth checking whether...").
        - Never compare the student to other students, to a class average, or to an
          expected standard.
        - Write for the stated academic level. Feedback pitched at the wrong level is
          worse than none.
        - Be specific to what was submitted. Never invent details, and never guess at
          content you cannot see — if the submission references an attachment you were
          not given, say the tutor should review it directly.
        - Keep the drafted feedback warm, concrete and actionable. Address the student
          as "you". Two or three points beat ten.
        - If the submission is too short, empty, off-topic, or otherwise impossible to
          assess, say exactly that in the summary, return empty lists, give a low
          confidence value, and draft feedback that asks the student for more.

        Respond only with the requested JSON object.
        PROMPT;

    private const string USER = <<<'PROMPT'
        Subject: {{ subject }}
        Academic level: {{ academic_level }}

        Assignment title: {{ assignment_title }}
        Assignment brief: {{ assignment_brief }}

        Student submission ({{ submission_note }}):
        {{ submission }}

        Produce:
        - summary: 1-3 sentences for the TUTOR describing what the student submitted
          and how it addresses the assignment.
        - strengths: up to 5 specific things the student did well. Empty list if the
          submission does not support any.
        - improvements: up to 5 specific, actionable things to work on. Empty list if
          the submission does not support any.
        - suggested_feedback: a draft message addressed to the student, in warm plain
          language, that the tutor can edit and send. No grade, no score, no verdict.
        - confidence: 0 to 1, reflecting how well the submission supports your reading
          of it. Lower it for short, ambiguous, or partly unavailable work.
        - requires_instructor_review: always true. A tutor reviews every draft.
        PROMPT;

    public static function definition(): PromptDefinition
    {
        return new PromptDefinition(
            key: self::KEY,
            version: self::VERSION,
            feature: AiFeature::HomeworkAssistant,
            capability: AiCapability::StructuredGeneration,
            systemTemplate: self::SYSTEM,
            userTemplate: self::USER,
            schemaKey: HomeworkFeedbackSchema::KEY,
            // The generation model: pitching feedback at a school
            // student's level and reading their reasoning is the part
            // that most rewards a stronger model.
            modelRole: AiModelRole::Generation,
            maxOutputTokens: 1600,
            // Slightly above the analytical default: this output is
            // prose a person will read, and greedy decoding makes
            // feedback drafts formulaic across submissions.
            temperature: 0.3,
        );
    }
}
