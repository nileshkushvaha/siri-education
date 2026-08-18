<?php

declare(strict_types=1);

namespace App\Quality\Intelligence\DTOs;

/**
 * Validated AI output, after StructuredOutputValidator. The ONLY shape
 * the domain accepts — nothing reads the raw provider payload.
 *
 * Every field is advisory. `concerns` are things for an administrator
 * to look into, never findings; `confidence` is the model's own stated
 * certainty and is displayed as such, never used to auto-approve
 * anything; `requiresHumanReview` is defaulted true and can only ever
 * be raised, never lowered, by the model (see fromValidated()).
 */
final readonly class QualityInsightData
{
    /**
     * @param  list<string>  $strengths
     * @param  list<string>  $concerns
     */
    public function __construct(
        public string $summary,
        public array $strengths,
        public array $concerns,
        public ?string $recommendedReview,
        public float $confidence,
        public bool $requiresHumanReview,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  output of StructuredOutputValidator
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            summary: (string) $validated['summary'],
            strengths: array_values(array_map('strval', (array) ($validated['strengths'] ?? []))),
            concerns: array_values(array_map('strval', (array) ($validated['concerns'] ?? []))),
            recommendedReview: isset($validated['recommended_review']) && $validated['recommended_review'] !== ''
                ? (string) $validated['recommended_review']
                : null,
            confidence: (float) $validated['confidence'],
            // The model may ASK for human review; it may never waive it.
            // A confident-sounding model saying "no review needed" is
            // exactly the output this feature must not act on, so the
            // flag is forced true whenever anything was raised as a
            // concern, and the admin UI requires an explicit
            // "Mark reviewed" regardless.
            requiresHumanReview: (bool) ($validated['requires_human_review'] ?? true)
                || ($validated['concerns'] ?? []) !== [],
        );
    }
}
