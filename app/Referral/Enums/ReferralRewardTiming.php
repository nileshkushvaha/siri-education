<?php

declare(strict_types=1);

namespace App\Referral\Enums;

/**
 * When an eligible reward becomes creditable (SRS 16.14). Immediate
 * requires hold_days = 0; AfterHoldDays requires hold_days >= 1 — both
 * enforced by a DB CHECK. Phase 19D consumes this; nothing credits yet.
 */
enum ReferralRewardTiming: string
{
    case Immediate = 'immediate';
    case AfterHoldDays = 'after_hold_days';

    public function label(): string
    {
        return match ($this) {
            self::Immediate => 'Immediately on eligibility',
            self::AfterHoldDays => 'After a hold period',
        };
    }
}
