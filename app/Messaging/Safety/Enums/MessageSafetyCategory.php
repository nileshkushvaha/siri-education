<?php

declare(strict_types=1);

namespace App\Messaging\Safety\Enums;

/**
 * What kind of risk a finding describes. Kept as separate categories
 * rather than one "moderation" bucket because the four are detected by
 * genuinely different mechanisms, carry different consequences, and are
 * reviewed by people asking different questions.
 *
 * ContactSharing and PaymentBypass are MARKETPLACE concerns — mostly
 * solved by deterministic rules, with AI only for intent that no
 * pattern can express. UnsafeContent is a SAFETY concern, answered by
 * the provider's own moderation classifier. OtherPolicyRisk is the
 * honest residue: something looked off and the model could not place it.
 */
enum MessageSafetyCategory: string
{
    case ContactSharing = 'contact_sharing';
    case PaymentBypass = 'payment_bypass';
    case UnsafeContent = 'unsafe_content';
    case OtherPolicyRisk = 'other_policy_risk';

    public function label(): string
    {
        return match ($this) {
            self::ContactSharing => 'Possible contact sharing',
            self::PaymentBypass => 'Possible payment bypass',
            self::UnsafeContent => 'Unsafe or abusive content',
            self::OtherPolicyRisk => 'Other policy risk',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ContactSharing, self::PaymentBypass => 'warning',
            self::UnsafeContent => 'danger',
            self::OtherPolicyRisk => 'gray',
        };
    }
}
