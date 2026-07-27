<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\Instructor\AvailabilityChangeRequiresConfirmationException;
use Closure;
use Filament\Notifications\Notification;

/**
 * Admin-side (Filament) impact confirmation for
 * availability reductions. The admin surfaces go through the same
 * authoritative services as the instructor UI, so a first attempt at an
 * affecting change throws (no mutation). This helper stores the impact
 * fingerprint SERVER-SIDE in the session (never a client boolean) and
 * shows a warning; repeating the exact same action consumes the stored
 * fingerprint as the acknowledgment. The services recompute and compare
 * the fingerprint under the instructor lock, so a stale acknowledgment
 * (new booking, changed values, other window edits) never authorizes a
 * materially different change — it just re-surfaces a refreshed warning.
 */
final class AvailabilityImpactConfirmation
{
    /**
     * @param  Closure(?string $impactConfirmation): mixed  $mutation
     * @return bool whether the mutation was applied
     */
    public static function run(string $actionKey, Closure $mutation): bool
    {
        $sessionKey = 'availability-impact:'.auth()->id().':'.$actionKey;
        // One-shot: the stored acknowledgment authorizes exactly the
        // next repeat of this action, never a later unrelated attempt.
        $token = session()->pull($sessionKey);

        try {
            $mutation(is_string($token) ? $token : null);

            return true;
        } catch (AvailabilityChangeRequiresConfirmationException $exception) {
            session()->put($sessionKey, $exception->impact->fingerprint);

            $summaries = collect($exception->impact->affectedSummaries)
                ->map(fn (array $summary): string => $summary['starts_at'].' ('.$summary['reference'].')')
                ->implode('; ');

            Notification::make()
                ->title(sprintf(
                    'This change affects %d confirmed upcoming lesson%s',
                    $exception->impact->affectedCount,
                    $exception->impact->affectedCount === 1 ? '' : 's',
                ))
                ->body(trim(sprintf(
                    'Those lessons remain scheduled and unchanged — the instructor must still teach them or handle them through the booking workflow. Repeat this exact action to confirm the availability change. %s',
                    $summaries !== '' ? 'Affected: '.$summaries.'.' : '',
                )))
                ->warning()
                ->persistent()
                ->send();

            return false;
        }
    }
}
