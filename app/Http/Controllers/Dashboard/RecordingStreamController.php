<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Booking\Services\RecordingAccessAuditor;
use App\Booking\Services\RecordingDeliveryService;
use App\Http\Controllers\Controller;
use App\Models\Recording;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The media source behind the student's video element — the real
 * security boundary for playback, and the reason Google Drive is never
 * the authorization layer.
 *
 * Every request, including every Range request a seeking player
 * issues, re-checks RecordingPolicy::watch() against the authenticated
 * session and the canonical recording row. There is no playback token,
 * no signed URL and no pre-generated link: a copied <video src> is
 * worthless outside an authorized session, and the URL keys on the
 * recording id, never on a storage or provider identifier.
 *
 * Denials from an authenticated user are audited (a student probing
 * ids, a withheld recording being retried); a guest never reaches
 * this — the dashboard group's auth middleware redirects first.
 */
final class RecordingStreamController extends Controller
{
    public function __invoke(
        Request $request,
        Recording $recording,
        RecordingDeliveryService $delivery,
        RecordingAccessAuditor $auditor,
    ): Response {
        if (Gate::inspect('watch', $recording)->denied()) {
            $auditor->accessDenied($request->user(), $recording, 'watch');

            abort(403);
        }

        // PLAYER REQUESTS ONLY. Browsers describe every request with
        // Fetch Metadata: pasting the URL into the address bar is a
        // navigation (`Sec-Fetch-Mode: navigate` / `Sec-Fetch-Dest:
        // document`), a media element's requests are same-origin and
        // non-navigational (Chrome labels them dest `empty`, mode
        // `no-cors`, site `same-origin`; Firefox/Safari dest `video`),
        // and a foreign page is `cross-site`. Navigations go back to the
        // watch page; anything cross-site is refused; browsers without
        // Fetch Metadata must carry a same-origin Referer, which a media
        // element always does. This closes the "open the link and save
        // the file" path — a deterrent, not a guarantee: an authorized
        // viewer with developer tools can still copy bytes they may watch.
        $mode = $request->headers->get('Sec-Fetch-Mode');
        $destination = $request->headers->get('Sec-Fetch-Dest');
        $site = $request->headers->get('Sec-Fetch-Site');

        if ($mode === 'navigate' || $destination === 'document') {
            return redirect()->route('dashboard.recordings.watch', $recording);
        }

        $isPlayerRequest = ($mode !== null || $destination !== null || $site !== null)
            ? in_array($site, ['same-origin', 'same-site'], true)
            : $this->sameOriginReferer($request);

        if (! $isPlayerRequest) {
            // Operational, not audit: which client shape was refused, so a
            // browser that labels media requests differently is found
            // from the log rather than from a "play does nothing" report.
            Log::info('Recording stream refused for a non-player request.', [
                'recording_id' => $recording->getKey(),
                'user_id' => $request->user()?->id,
                'sec_fetch_dest' => $destination,
                'sec_fetch_mode' => $mode,
                'sec_fetch_site' => $site,
                'referer_host' => parse_url((string) $request->headers->get('Referer'), PHP_URL_HOST),
                'user_agent' => substr((string) $request->userAgent(), 0, 160),
            ]);

            abort(403, 'This recording can only be played inside SIRI Education.');
        }

        return $delivery->respond(
            $recording,
            $request->headers->get('Range'),
            inline: true,
            headOnly: $request->isMethod('HEAD'),
        );
    }

    private function sameOriginReferer(Request $request): bool
    {
        $referer = $request->headers->get('Referer');

        if ($referer === null || $referer === '') {
            return false;
        }

        return strcasecmp((string) parse_url($referer, PHP_URL_HOST), $request->getHost()) === 0;
    }
}
