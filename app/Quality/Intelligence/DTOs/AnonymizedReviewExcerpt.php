<?php

declare(strict_types=1);

namespace App\Quality\Intelligence\DTOs;

/**
 * One review as the model is allowed to see it: a rating, a label, and
 * text that has been through the anonymizer.
 *
 * `label` is deliberately positional ("Review A") rather than any kind
 * of identifier. Sending the review UUID would let a model output echo
 * a database key into stored text, and would serve no analytical
 * purpose — the model is asked about patterns, never about rows.
 */
final readonly class AnonymizedReviewExcerpt
{
    public function __construct(
        public string $label,
        public int $overallRating,
        public ?string $text,
        /** @var list<string> configured tag labels the student selected */
        public array $tags,
    ) {}
}
