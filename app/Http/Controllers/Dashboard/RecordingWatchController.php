<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Booking\Services\RecordingAccessAuditor;
use App\Http\Controllers\Controller;
use App\Models\Recording;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The student's in-application player page (SRS §12.20 — student
 * access "based on policy"; the policy is RecordingPolicy::watch()).
 *
 * The page carries no provider or storage detail of any kind: the
 * video element's source is the application's own stream route, keyed
 * on the recording, and the only identifier rendered is the booking's
 * public reference — the same PII-free reference already used on
 * invoices, meeting titles and recording filenames. The watermark
 * carries it too: a leaked capture is attributed to the booking, and
 * through it to the account, without printing a database id or any
 * personal detail on the video. Opening the page is audited as the
 * "recording access granted" event (SRS §24.17); the stream route
 * re-authorizes every byte request on its own.
 */
final class RecordingWatchController extends Controller
{
    public function __invoke(Request $request, Recording $recording, RecordingAccessAuditor $auditor): View
    {
        $viewer = $request->user();

        if (Gate::inspect('watch', $recording)->denied()) {
            $auditor->accessDenied($viewer, $recording, 'watch');

            abort(403);
        }

        $auditor->playbackOpened($viewer, $recording);

        $recording->loadMissing(['booking.type', 'booking.instructor']);

        $reference = $recording->booking?->reference;

        return view('student.recordings.watch', [
            'recording' => $recording,
            'booking' => $recording->booking,
            'watermark' => [
                'platform' => (string) config('app.name'),
                'reference' => is_string($reference) && preg_match('/^[A-Za-z0-9\-]{1,32}$/', $reference) === 1
                    ? $reference
                    : 'Lesson',
                'moveSeconds' => max(0, (int) config('recordings.playback.watermark_move_seconds', 12)),
            ],
        ]);
    }
}
