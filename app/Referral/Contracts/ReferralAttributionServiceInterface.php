<?php

declare(strict_types=1);

namespace App\Referral\Contracts;

use App\Models\ReferralAttribution;
use App\Models\User;

interface ReferralAttributionServiceInterface
{
    /**
     * Attempt referral attribution for a just-registered student.
     *
     * Never throws and never blocks registration: every invalid
     * condition (feature disabled, unknown/malformed/disabled code,
     * ineligible or non-student referrer, self-referral, existing
     * attribution) returns null without revealing which check failed.
     *
     * @param  string|null  $source  'link' when the code came from a ?ref= prefill, anything else records manual entry
     */
    public function attributeFromRegistration(User $referredStudent, ?string $rawCode, ?string $source = null): ?ReferralAttribution;
}
