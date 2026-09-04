<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Booking\Services\RecordingDeliveryService;
use App\Http\Controllers\Controller;
use App\Models\Recording;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Administrative download of the ORIGINAL recording file as an
 * attachment (SRS §12.20 — administrator access).
 *
 * Lives under /admin because the people who hold View:Recording use
 * the admin portal and are redirected away from /dashboard/* by
 * EnsureFrontendPortal; the student portal has its own, narrower
 * playback routes (RecordingWatchController / RecordingStreamController)
 * and no download. One delivery service, two portal entry points —
 * not a second authorization system.
 *
 * Every request re-checks RecordingPolicy::download() live, so a
 * bookmarked or forwarded link is worthless without a currently
 * authenticated, currently permitted session. The response never
 * reveals where the bytes live: the storage locator is resolved
 * server-side and the content is proxied back as a stream.
 */
final class RecordingDownloadController extends Controller
{
    public function __invoke(Request $request, Recording $recording, RecordingDeliveryService $delivery): Response
    {
        // 'download' is stricter than 'view': it additionally requires
        // the recording to still HAVE a stored object, so an expired or
        // failed recording is never half-served.
        Gate::authorize('download', $recording);

        return $delivery->respond($recording, null, inline: false, headOnly: $request->isMethod('HEAD'));
    }
}
