<?php

declare(strict_types=1);

namespace App\Compliance\Actions;

use App\Compliance\Enums\SuspiciousActivityFlagStatus;
use App\Compliance\Exceptions\InvalidSuspiciousActivityFlagTransitionException;
use App\Models\SuspiciousActivityFlag;

/**
 * The one place a flag's status is written. Guards every change
 * through SuspiciousActivityFlagStatus::canTransitionTo() — mirrors
 * TransitionInstructorQualityAlertStatusAction. Clears
 * `active_fingerprint` on every transition into a terminal status so
 * the fingerprint becomes free for a future, genuinely new flag while
 * this row is preserved forever as history.
 *
 * @throws InvalidSuspiciousActivityFlagTransitionException
 */
final class TransitionSuspiciousActivityFlagStatusAction
{
    /** @param array<string, mixed> $extra */
    public function execute(SuspiciousActivityFlag $flag, SuspiciousActivityFlagStatus $next, array $extra = []): SuspiciousActivityFlag
    {
        if (! $flag->status->canTransitionTo($next)) {
            throw InvalidSuspiciousActivityFlagTransitionException::between($flag->status, $next);
        }

        $attributes = [...$extra, 'status' => $next, 'version' => $flag->version + 1];

        if ($next->isTerminal()) {
            $attributes['active_fingerprint'] = null;
        }

        $flag->fill($attributes)->save();

        return $flag;
    }
}
