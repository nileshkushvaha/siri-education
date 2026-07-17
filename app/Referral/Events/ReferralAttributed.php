<?php

declare(strict_types=1);

namespace App\Referral\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new student registered with a valid referral code and the permanent
 * attribution row was created. Carries stable identifiers only — never
 * models — so listeners (Phase 19D notifications, reward evaluation)
 * load fresh state and can never leak referred-student PII by accident.
 *
 * Phase 19B attaches no listeners.
 */
final class ReferralAttributed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $attributionId,
        public readonly int $referrerId,
        public readonly int $referredStudentId,
    ) {}
}
