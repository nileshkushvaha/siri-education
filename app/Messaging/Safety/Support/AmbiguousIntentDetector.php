<?php

declare(strict_types=1);

namespace App\Messaging\Safety\Support;

use App\Messaging\Support\LeakageDetector;

/**
 * THE COST AND PRIVACY GATE. Decides whether one message is worth
 * sending to an AI provider at all.
 *
 * This is the single most important class in P4. Because message
 * analysis is automatic — no human picks each message, unlike P1-P3 —
 * the compensating control is that the overwhelming majority of
 * messages never leave the platform. Two rules do that:
 *
 *  1. If LeakageDetector already fired, the answer is known. An email
 *     address in a message is a fact, free to detect, and instantly
 *     explainable to an admin. Paying a provider to re-confirm it would
 *     be worse on every axis — cost, latency, privacy, explainability.
 *  2. If the message trips none of the ambiguity phrases below, it is
 *     ordinary tutoring conversation and is never sent anywhere.
 *
 * What remains is the genuine residue: messages that avoid every
 * pattern while clearly proposing to move off-platform — "we can sort
 * the rest out between ourselves", "easier to reach me elsewhere". No
 * regex expresses that intent, which is exactly where a language model
 * earns its cost.
 *
 * The phrase list is deliberately narrow and biased toward missing
 * cases rather than over-triggering: a missed message is caught by
 * reporting and by the deterministic layer on the sender's next
 * attempt, while an over-eager gate would quietly turn this into
 * blanket surveillance of a marketplace's private conversations.
 */
final class AmbiguousIntentDetector
{
    /**
     * Deliberately phrases, not single words. "directly" and "outside"
     * alone appear constantly in ordinary tutoring talk ("solve it
     * directly", "outside the syllabus"); it is the combination that
     * carries intent.
     */
    private const array AMBIGUITY_PHRASES = [
        // Moving the conversation elsewhere.
        'somewhere else', 'another app', 'different app', 'off here', 'off this app',
        'off the platform', 'off platform', 'outside the platform', 'outside this platform',
        'outside the app', 'not on here', 'not through here', 'reach me directly',
        'contact me directly', 'message me directly', 'find me online', 'find me on',
        'same name everywhere', 'my details are', 'my number is', 'my handle is',
        'add me on', 'text me on', 'call me on', 'dm me',

        // Arranging money outside the platform.
        'pay me directly', 'pay directly', 'pay outside', 'without the platform',
        'without the app', 'cheaper if', 'skip the fees', 'avoid the fees',
        'no commission', 'cash instead', 'bank transfer', 'transfer the money',
        'settle between us', 'between ourselves', 'sort it out between',
        'arrange payment', 'private arrangement', 'private lessons instead',
    ];

    public function __construct(
        private readonly LeakageDetector $leakage,
    ) {}

    /**
     * True only when the message is genuinely ambiguous: no
     * deterministic flag fired, and something in it suggests an
     * off-platform intent.
     */
    public function warrantsAiAnalysis(string $body): bool
    {
        if ($this->leakage->detect($body) !== []) {
            // Already answered, for free, with an explanation.
            return false;
        }

        return $this->trippedPhrases($body) !== [];
    }

    /**
     * The phrases that tripped — recorded on the finding as the reason
     * this message was analysed at all, so "why was my message sent to
     * an AI provider?" always has a concrete, auditable answer.
     *
     * @return list<string>
     */
    public function trippedPhrases(string $body): array
    {
        $lower = mb_strtolower($body);
        $tripped = [];

        foreach (self::AMBIGUITY_PHRASES as $phrase) {
            if (str_contains($lower, $phrase)) {
                $tripped[] = $phrase;
            }
        }

        return $tripped;
    }
}
