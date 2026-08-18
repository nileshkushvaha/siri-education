<?php

declare(strict_types=1);

namespace App\Messaging\Safety\Resolvers;

use App\Ai\Contracts\AiTaskInputResolverInterface;
use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\Enums\AiFailureCode;
use App\Ai\Exceptions\AiException;
use App\Ai\Support\AiTextRedactor;
use App\Messaging\Safety\Contracts\MessageSafetyFindingRepositoryInterface;
use App\Messaging\Safety\DTOs\CommunicationSafetyInput;
use App\Models\MessageSafetyFinding;

/**
 * Turns a queued descriptor back into prompt variables — the moment a
 * message is read, and the only place it is rendered for a provider.
 *
 * ONE MESSAGE, NO HISTORY. This resolver deliberately loads no sibling
 * messages, no conversation, no participants and no profile. It could:
 * the conversation is one relation away, and context would measurably
 * improve intent detection. It does not, because a single flagged
 * phrase must never drag a private conversation between a student and
 * their tutor to a third party.
 *
 * Reading at execution time (never at dispatch) also means a message
 * deleted or a conversation closed before the job ran is simply not
 * analysed.
 */
final class CommunicationSafetyInputResolver implements AiTaskInputResolverInterface
{
    /** A message is capped at 2000 characters on submission; this is a floor, not a truncation in practice. */
    public const int MAX_MESSAGE_CHARACTERS = 2000;

    public function __construct(
        private readonly MessageSafetyFindingRepositoryInterface $findings,
        private readonly AiTextRedactor $redactor,
    ) {}

    public function resolve(AiTaskDescriptor $descriptor): array
    {
        $finding = $this->finding($descriptor);
        $message = $finding->message;

        if ($message === null) {
            throw new AiException('The message this analysis belongs to no longer exists.', AiFailureCode::NotConfigured);
        }

        $conversation = $message->conversation;

        if ($conversation === null) {
            throw new AiException('The conversation this message belongs to no longer exists.', AiFailureCode::NotConfigured);
        }

        // The sender's ROLE, derived from the conversation's own
        // participant columns — never a name, never an id. The same
        // sentence carries different weight from each side of a tutoring
        // relationship, and the role is the whole of what the model
        // needs to know about who wrote it.
        $senderRole = $message->sender_id === $conversation->instructor_id ? 'instructor' : 'student';

        $body = $this->redactor->redact($message->body, [], self::MAX_MESSAGE_CHARACTERS);

        if ($body === null) {
            throw new AiException('This message has no analysable text.', AiFailureCode::NotConfigured);
        }

        $input = new CommunicationSafetyInput(
            senderRole: $senderRole,
            messageBody: $body,
            triageReasons: array_map('strval', $finding->detected_patterns ?? []),
        );

        return [
            'sender_role' => $input->senderRole,
            'triage_reasons' => $input->triageReasons === []
                ? 'reported by a user'
                : implode(', ', $input->triageReasons),
            'message' => $input->messageBody,
        ];
    }

    private function finding(AiTaskDescriptor $descriptor): MessageSafetyFinding
    {
        $finding = $descriptor->correlationId === null
            ? null
            : $this->findings->find($descriptor->correlationId);

        if ($finding === null) {
            throw new AiException('The safety finding this run belongs to no longer exists.', AiFailureCode::NotConfigured);
        }

        return $finding;
    }
}
