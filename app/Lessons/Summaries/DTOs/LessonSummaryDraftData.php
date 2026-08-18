<?php

declare(strict_types=1);

namespace App\Lessons\Summaries\DTOs;

/**
 * Validated AI output, after StructuredOutputValidator.
 *
 * There is no mastery level, no progress percentage, no student level
 * and no grade — not merely omitted here, but absent from the schema
 * above it, so a model has nowhere to put one. Those fields would
 * become authoritative-looking metrics the moment they existed, and
 * progress in this platform is decided by instructors and deterministic
 * rules, never by a language model's impression of one lesson.
 */
final readonly class LessonSummaryDraftData
{
    /**
     * @param  list<string>  $topicsCovered
     * @param  list<string>  $strengthsObserved
     * @param  list<string>  $practiceRecommendations
     * @param  list<string>  $nextFocus
     */
    public function __construct(
        public string $lessonSummary,
        public array $topicsCovered,
        public array $strengthsObserved,
        public array $practiceRecommendations,
        public array $nextFocus,
        public float $confidence,
        public bool $requiresInstructorReview,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            lessonSummary: (string) $validated['lesson_summary'],
            topicsCovered: self::strings($validated['topics_covered'] ?? []),
            strengthsObserved: self::strings($validated['strengths_observed'] ?? []),
            practiceRecommendations: self::strings($validated['practice_recommendations'] ?? []),
            nextFocus: self::strings($validated['next_focus'] ?? []),
            confidence: (float) $validated['confidence'],
            // Hardcoded, not read from the model: no lesson record may
            // ever enter the platform's documentation without an
            // instructor approving it, so this is not a value the model
            // gets a vote on.
            requiresInstructorReview: true,
        );
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_map('strval', $values));
    }
}
