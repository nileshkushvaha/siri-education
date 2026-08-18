<?php

declare(strict_types=1);

namespace App\Messaging\Safety\DTOs;

use App\Messaging\Safety\Enums\MessageSafetyCategory;
use App\Messaging\Safety\Enums\MessageSafetyRiskLevel;

/**
 * Validated AI output for an intent analysis.
 *
 * There is no `block_message`, `ban_user`, `suspend_account`,
 * `restrict`, or `action` field — not omitted here, but absent from the
 * schema above it, so a model has nowhere to put an instruction. The
 * platform takes no action from this object; it records a finding for a
 * human.
 */
final readonly class CommunicationRiskData
{
    public function __construct(
        public ?MessageSafetyCategory $category,
        public MessageSafetyRiskLevel $riskLevel,
        public string $reason,
        public float $confidence,
        public bool $requiresReview,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        $category = (string) $validated['category'];

        return new self(
            // 'none' is a first-class answer: most analysed messages
            // turn out to be innocent, and a model that cannot say so
            // will invent a category to fill the field.
            category: $category === 'none' ? null : MessageSafetyCategory::from($category),
            riskLevel: MessageSafetyRiskLevel::from((string) $validated['risk_level']),
            reason: (string) $validated['reason'],
            confidence: (float) $validated['confidence'],
            // Hardcoded: no finding in this system is ever acted on
            // without a person, so the model gets no vote.
            requiresReview: true,
        );
    }

    /** Nothing to record when the model found no risk at all. */
    public function isClean(): bool
    {
        return $this->category === null;
    }
}
