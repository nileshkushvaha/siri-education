<?php

declare(strict_types=1);

namespace App\Alerts\Actions;

use App\Alerts\Enums\OperationalAlertStatus;
use App\Alerts\Exceptions\InvalidOperationalAlertTransitionException;
use App\Models\OperationalAlert;

/**
 * The one place an alert's status is written. Guards every change
 * through `OperationalAlertStatus::canTransitionTo()` — mirrors
 * `TransitionSuspiciousActivityFlagStatusAction`. Clears
 * `active_fingerprint` on transition into the terminal Resolved status
 * so the fingerprint becomes free for a future, genuinely new episode
 * while this row is preserved forever as history.
 *
 * @throws InvalidOperationalAlertTransitionException
 */
final class TransitionOperationalAlertStatusAction
{
    /** @param array<string, mixed> $extra */
    public function execute(OperationalAlert $alert, OperationalAlertStatus $next, array $extra = []): OperationalAlert
    {
        if (! $alert->status->canTransitionTo($next)) {
            throw InvalidOperationalAlertTransitionException::between($alert->status, $next);
        }

        $attributes = [...$extra, 'status' => $next, 'version' => $alert->version + 1];

        if ($next->isTerminal()) {
            $attributes['active_fingerprint'] = null;
        }

        $alert->fill($attributes)->save();

        return $alert;
    }
}
