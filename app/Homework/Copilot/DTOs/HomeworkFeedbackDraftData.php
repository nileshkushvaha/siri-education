<?php

declare(strict_types=1);

namespace App\Homework\Copilot\DTOs;

/**
 * Validated AI output, after StructuredOutputValidator. The only shape
 * the domain accepts.
 *
 * There is no score, mark, grade or pass/fail field — not omitted from
 * this DTO, but absent from the schema above it, so a model has nowhere
 * to put one. Grading remains entirely the instructor's, through the
 * pre-existing `grade` field on the assignment, which no AI code path
 * touches.
 */
final readonly class HomeworkFeedbackDraftData
{
    /**
     * @param  list<string>  $strengths
     * @param  list<string>  $improvements
     */
    public function __construct(
        public string $summary,
        public array $strengths,
        public array $improvements,
        public string $suggestedFeedback,
        public float $confidence,
        public bool $requiresInstructorReview,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            summary: (string) $validated['summary'],
            strengths: array_values(array_map('strval', (array) ($validated['strengths'] ?? []))),
            improvements: array_values(array_map('strval', (array) ($validated['improvements'] ?? []))),
            suggestedFeedback: (string) $validated['suggested_feedback'],
            confidence: (float) $validated['confidence'],
            // Hardcoded true, not read from the model. Unlike P1 — where
            // the model may raise the review requirement — there is no
            // case in which an unreviewed AI draft may reach a student,
            // so this is not a value the model gets a vote on. The field
            // stays in the schema because the prompt is written to make
            // the model state it, which keeps the model's framing
            // honest, but the domain never trusts the answer.
            requiresInstructorReview: true,
        );
    }
}
