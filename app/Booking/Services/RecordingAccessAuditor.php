<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Models\Recording;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The audit trail of recording ACCESS — who opened a recording, and
 * who was refused — as distinct from RecordingLifecycleNotifier, which
 * records what happened TO a recording.
 *
 * Deliberately sparse (SRS §24.17 "Recording access granted"): one
 * entry when a viewer opens the watch page, and one when an
 * authenticated user is refused. No heartbeat, no per-Range entry, no
 * watch-time telemetry — a seeking player issues dozens of range
 * requests a minute, and logging each would bury every other audit
 * channel in noise while telling an investigator nothing the
 * page-open did not. Request context (ip, user agent, route) is the
 * existing AuditTrailService convention and comes for free.
 *
 * Refusals are RATE-LIMITED PER VIEWER AND RECORDING. The audit table
 * is durable, and an authenticated user hammering one recording id
 * they know but may not watch would otherwise be able to write
 * thousands of permanent rows. The first refusal in each window is
 * the audit entry (that is the signal an investigator needs); repeats
 * inside the window go to the application log instead, where volume
 * is expected and rotated. A guest never reaches this — the dashboard
 * group's auth middleware redirects first — and a non-existent id
 * 404s before authorization, so neither can write anything here.
 */
final class RecordingAccessAuditor
{
    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    public function playbackOpened(User $viewer, Recording $recording): void
    {
        $this->audit->logUser(
            $viewer,
            'recordings',
            'recording_playback_opened',
            'Lesson recording opened for playback.',
            $recording,
            ['booking_id' => $recording->booking_id],
        );
    }

    public function accessDenied(User $viewer, Recording $recording, string $ability): void
    {
        $key = sprintf('recordings:access-denied:%d:%s:%s', $viewer->id, $recording->getKey(), $ability);

        $window = max(1, (int) config('recordings.playback.denial_audit_window_seconds', 900));

        if (! Cache::add($key, true, $window)) {
            Log::info('Repeated lesson recording access refusal.', [
                'user_id' => $viewer->id,
                'recording_id' => $recording->getKey(),
                'ability' => $ability,
            ]);

            return;
        }

        $this->audit->logUser(
            $viewer,
            'recordings',
            'recording_access_denied',
            'Lesson recording access refused.',
            $recording,
            ['booking_id' => $recording->booking_id, 'ability' => $ability],
        );
    }
}
