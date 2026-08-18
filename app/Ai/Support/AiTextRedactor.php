<?php

declare(strict_types=1);

namespace App\Ai\Support;

use Illuminate\Support\Str;

/**
 * The mechanical half of preparing user-written text for a provider:
 * contact-shaped patterns, known participant names, stray digit runs,
 * and a hard length cap.
 *
 * Deliberately NOT a general-purpose "make this safe" service. It knows
 * nothing about whose text this is, what it means, or whether it is
 * safe to send at all — those are domain judgements, and a shared class
 * that pretended to make them would be weaker than the domain resolver
 * it replaced. The split is:
 *
 *   Domain  decides WHICH fields may travel, WHO the participants are,
 *           and how much of the content is appropriate to send.
 *   This    enforces the floor on whatever it is handed.
 *
 * KNOWN-NAME REDACTION IS THE LAYER THAT ACTUALLY WORKS. No pattern can
 * recognise a name, but a caller knows exactly who the record's
 * participants are, so it passes them in and they are removed by exact,
 * word-boundary match. That is what catches "my daughter Mira loved it".
 * A caller that passes no hints gets pattern scrubbing only — and should
 * know that is a weaker guarantee.
 *
 * Redaction is irreversible: the replaced token is dropped, never
 * recorded next to its replacement, never logged.
 */
final class AiTextRedactor
{
    private const string EMAIL_PATTERN = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/';

    private const string URL_PATTERN = '/\b(?:https?:\/\/|www\.)\S+/i';

    private const string PHONE_PATTERN = '/(?:\+?\d[\d\-.\s()]{6,}\d)/';

    private const string HANDLE_PATTERN = '/(?<![\w.])@[A-Za-z0-9_.]{2,30}\b/';

    /** Below this a "name" is too generic to redact safely ("Al", "Jo") and would mangle ordinary words. */
    private const int MIN_NAME_TOKEN_LENGTH = 3;

    /**
     * @param  list<string>  $identityHints  names of the record's participants — never emails or phone numbers, which the patterns already remove and which a hint list has no reason to carry
     */
    public function redact(?string $text, array $identityHints = [], ?int $maxCharacters = null): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $clean = $this->redactPatterns($text);
        $clean = $this->redactNames($clean, $identityHints);

        // Anything digit-shaped that survived (a stray id, an order
        // number) has no analytical value in any AI feature so far.
        $clean = preg_replace('/\d{4,}/', '[redacted]', $clean) ?? $clean;

        $clean = trim(preg_replace('/[ \t]{2,}/', ' ', $clean) ?? $clean);

        if ($clean === '') {
            return null;
        }

        return $maxCharacters === null ? $clean : Str::limit($clean, $maxCharacters);
    }

    /**
     * Normalizes a caller's raw name list into usable hints.
     *
     * Takes STRINGS, never User models, deliberately: knowing which
     * people are a record's participants — and which of their fields
     * count as a name — is domain knowledge, and app/Ai stays free of
     * domain models (AiArchitectureTest enforces it). Each domain maps
     * its own users to names and passes them here.
     *
     * @param  list<string|null>  $names
     * @return list<string>
     */
    public function normalizeHints(array $names): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (?string $name): string => trim((string) $name), $names),
            static fn (string $hint): bool => $hint !== '',
        )));
    }

    private function redactPatterns(string $text): string
    {
        // Links before phones: a phone scan over a raw URL would
        // otherwise partial-match its digits first.
        $text = preg_replace(self::EMAIL_PATTERN, '[redacted]', $text) ?? $text;
        $text = preg_replace(self::URL_PATTERN, '[redacted]', $text) ?? $text;
        $text = preg_replace(self::PHONE_PATTERN, '[redacted]', $text) ?? $text;

        return preg_replace(self::HANDLE_PATTERN, '[redacted]', $text) ?? $text;
    }

    /** @param list<string> $hints */
    private function redactNames(string $text, array $hints): string
    {
        $tokens = [];

        foreach ($hints as $hint) {
            // Both the full name and each part: people are referred to
            // by first name far more often than by full name.
            foreach ([$hint, ...(preg_split('/\s+/', $hint) ?: [])] as $token) {
                $token = trim((string) $token);

                if (mb_strlen($token) >= self::MIN_NAME_TOKEN_LENGTH) {
                    $tokens[mb_strtolower($token)] = $token;
                }
            }
        }

        // Longest first, so "Anna Smith" becomes one [name] rather than
        // two.
        usort($tokens, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($tokens as $token) {
            $text = preg_replace(
                '/(?<![\p{L}])'.preg_quote($token, '/').'(?![\p{L}])/iu',
                '[name]',
                $text,
            ) ?? $text;
        }

        return $text;
    }
}
