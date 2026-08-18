<?php

declare(strict_types=1);

namespace App\Messaging\Safety\DTOs;

/**
 * EVERYTHING a provider may see about a message, and nothing else.
 *
 * The narrowest input DTO in the whole AI platform, on purpose. P1-P3
 * were human-initiated, so a person had chosen that particular record;
 * here the analysis is automatic, and the compensating control is that
 * almost nothing travels:
 *
 *   - the message body, redacted;
 *   - who wrote it, as a ROLE ("student" / "instructor") — never a name,
 *     never an id — because the same sentence means different things
 *     from each side of a tutoring relationship;
 *   - nothing else.
 *
 * Structurally absent: conversation history, any other message, the
 * other participant, names, emails, phone numbers, user ids, profile
 * data, booking or lesson context, payment or wallet data, account
 * status, and anything authentication-related.
 *
 * NO CONVERSATION HISTORY BY DEFAULT is a deliberate accuracy trade.
 * Context would improve intent detection — "yes, that works" is
 * meaningless alone — and it would also mean one flagged message drags
 * an entire private conversation to a third party. The platform
 * accepts weaker single-message accuracy in exchange, and the prompt
 * tells the model it is seeing one message in isolation so it lowers
 * confidence rather than inventing context.
 */
final readonly class CommunicationSafetyInput
{
    public function __construct(
        /** 'student' or 'instructor' — a role, never an identity. */
        public string $senderRole,
        public string $messageBody,
        /** Why this message was selected for analysis at all — the triage phrases that tripped. */
        public array $triageReasons,
    ) {}

    /**
     * Counts and reasons only — never the message text.
     *
     * @return array<string, mixed>
     */
    public function toProvenance(): array
    {
        return [
            'sender_role' => $this->senderRole,
            'message_characters' => mb_strlen($this->messageBody),
            'triage_reasons' => $this->triageReasons,
        ];
    }
}
