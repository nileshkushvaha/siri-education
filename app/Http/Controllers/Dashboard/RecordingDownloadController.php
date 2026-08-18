<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Booking\DTOs\RecordingLocator;
use App\Booking\Services\RecordingStagingArea;
use App\Booking\Storage\RecordingStorageResolver;
use App\Http\Controllers\Controller;
use App\Models\Recording;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The real security boundary for lesson recordings, and the reason
 * Google Drive is never the authorization layer.
 *
 * Every request re-checks RecordingPolicy live, so a bookmarked or
 * forwarded link is worthless without a currently authenticated,
 * currently authorized session. There is no signed URL, no
 * pre-generated link and no "anyone with the link" sharing to leak,
 * and the response never reveals where the bytes actually live: the
 * storage locator is resolved server-side and the content is proxied
 * back as a stream.
 *
 * That indirection is also what makes the S3 migration invisible here.
 * This controller asks RecordingStorageResolver for "the backend this
 * recording lives in" and streams whatever it returns; it contains no
 * drive.google.com, no bucket name, and no backend branching at all.
 *
 * Delivery is authenticated DOWNLOAD, not streaming playback — the
 * current product requirement (SRS §12.20) is permission-controlled
 * access to a stored recording, not an in-browser player. Range
 * requests are therefore explicitly NOT advertised rather than
 * half-implemented: `Accept-Ranges: none` tells a client the truth
 * instead of inviting seeks this response cannot honour.
 */
final class RecordingDownloadController extends Controller
{
    public function __invoke(Recording $recording, RecordingStorageResolver $storages): StreamedResponse
    {
        // 'download' is stricter than 'view': it additionally requires
        // the recording to still HAVE a stored object, so an expired or
        // failed recording is never half-served.
        Gate::authorize('download', $recording);

        $locator = RecordingLocator::fromRecording($recording);
        abort_if($locator === null, 404);

        $stream = $storages->forRecording($recording)->read($locator);

        return response()->stream(
            function () use ($stream): void {
                // Fixed-size chunks: a class recording is never
                // materialized in PHP memory on the way out either.
                while (! feof($stream)) {
                    echo fread($stream, 1024 * 1024);
                    flush();
                }

                fclose($stream);
            },
            200,
            array_filter([
                'Content-Type' => $recording->mime_type ?? 'application/octet-stream',
                'Content-Length' => $recording->size_bytes !== null ? (string) $recording->size_bytes : null,
                'Content-Disposition' => sprintf('attachment; filename="%s"', $this->downloadFilename($recording)),
                'Accept-Ranges' => 'none',
                // Private video must never be cached by a shared proxy.
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]),
        );
    }

    /**
     * The name the viewer's browser saves. Built from the booking's
     * public reference only — never a participant's name or email,
     * which would leak the other party's identity into a filename.
     */
    private function downloadFilename(Recording $recording): string
    {
        $reference = $recording->booking?->reference;
        $safe = is_string($reference) && preg_match('/^[A-Za-z0-9\-]{1,32}$/', $reference) === 1
            ? $reference
            : 'recording';

        // From the recorded mime type, never from the storage locator —
        // a Drive locator is an opaque file id with no extension at all,
        // and parsing one here would reintroduce backend knowledge.
        $extension = RecordingStagingArea::extensionFor($recording->mime_type ?? '', 'recording.mp4');

        return sprintf('lesson-%s.%s', $safe, $extension);
    }
}
