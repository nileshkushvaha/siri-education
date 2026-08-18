<?php

declare(strict_types=1);

namespace App\Quality\Intelligence\Services;

use App\Ai\Support\AiTextRedactor;
use App\Models\User;
use App\Reviews\Support\ReviewContentSanitizer;

/**
 * The last thing review text passes through before it can leave the
 * platform, and the reason a student's identity cannot travel with it.
 *
 * Two layers, deliberately overlapping:
 *
 *  1. ReviewContentSanitizer, re-run. Stored review text is already
 *     sanitized at submission, so this is defence in depth — it costs
 *     nothing and means a row written before that rule existed, or
 *     imported, or edited by a future path, still cannot carry an email
 *     or a phone number to a third party. It is also review-specific in
 *     a way the shared redactor is not: it strips HTML and flags
 *     payment-solicitation and promotional-spam phrasing.
 *  2. AiTextRedactor, the platform-wide floor: contact patterns,
 *     known-participant name redaction, residual digit runs and the
 *     length cap.
 *
 * The name layer is the one that actually works, and it works only
 * because this class knows whose review each excerpt is — see
 * identityHintsFor(). That domain knowledge is exactly what a generic
 * sanitizer could not supply, which is why this class still exists
 * rather than being replaced by the shared one.
 */
final class QualityInsightAnonymizer
{
    /** Long enough to carry the substance of a review, short enough that nothing else rides along. */
    public const int MAX_EXCERPT_CHARACTERS = 400;

    public function __construct(
        private readonly AiTextRedactor $redactor,
    ) {}

    /**
     * @param  list<string>  $identityHints  names known to relate to this review (student, guardian, child, instructor)
     */
    public function anonymize(?string $text, array $identityHints = []): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $sanitized = ReviewContentSanitizer::sanitize($text)->content;

        return $this->redactor->redact($sanitized, $identityHints, self::MAX_EXCERPT_CHARACTERS);
    }

    /**
     * The domain-side half of the name layer: which people are this
     * record's participants, and which of their fields are names. The
     * shared redactor deliberately does not know either.
     *
     * @return list<string>
     */
    public function identityHintsFor(?User $student, ?User $instructor): array
    {
        $names = [];

        foreach ([$student, $instructor] as $user) {
            if ($user === null) {
                continue;
            }

            $names[] = (string) $user->name;
            $names[] = (string) $user->first_name;
            $names[] = (string) $user->last_name;
            $names[] = (string) $user->full_name;
        }

        return $this->redactor->normalizeHints($names);
    }
}
