<?php

declare(strict_types=1);

namespace App\Referral\Contracts;

use App\Models\ReferralAttribution;
use App\Models\User;
use App\Referral\Exceptions\ReferralException;
use Illuminate\Auth\Access\AuthorizationException;

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

    /**
     * Exceptional, audited attribution correction (SRS
     * 16.10 "Admin may correct attribution only with permission and
     * audit reason"). Requires CorrectReferralAttribution and a reason;
     * locks the attribution; refuses once ANY reward row exists (the
     * safe rule — financial history is never re-owned); enforces every
     * registration-time invariant (eligible active student referrer, no
     * self-referral, student-only, single referrer).
     *
     * @throws ReferralException
     * @throws AuthorizationException
     */
    public function correctAttribution(ReferralAttribution $attribution, User $newReferrer, User $admin, string $reason): ReferralAttribution;
}
