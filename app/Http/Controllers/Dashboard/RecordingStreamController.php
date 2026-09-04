<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Booking\Services\RecordingAccessAuditor;
use App\Booking\Services\RecordingDeliveryService;
use App\Http\Controllers\Controller;
use App\Models\Recording;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

        return $delivery->respond(
            $recording,
            $request->headers->get('Range'),
            inline: true,
            headOnly: $request->isMethod('HEAD'),
        );
    }
}
