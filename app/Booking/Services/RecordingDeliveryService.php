<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\DTOs\RecordingByteRange;
use App\Booking\DTOs\RecordingLocator;
use App\Booking\Exceptions\RecordingStorageException;
use App\Booking\Storage\RecordingStorageResolver;
use App\Models\Recording;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turns an ALREADY-AUTHORIZED recording into an HTTP response — the one
 * place bytes leave the application, shared by student playback and
 * administrative download so there is exactly one set of delivery
 * rules to audit.
 *
 * How bytes move:
 *
 *   browser → Laravel → this class → RecordingStorage::read(locator, window)
 *           → backend (Drive: a streamed HTTPS media GET with a Range
 *             header; filesystem: an fseek'd file handle)
 *           → read in CHUNK-sized pieces → echoed and flushed
 *
 * STREAMED, NEVER BUFFERED. The storage stream is a socket or file
 * handle; the loop reads at most `recordings.playback.chunk_bytes` at a
 * time and writes it out before reading the next. Peak memory per
 * request is one chunk plus transport overhead, independent of the
 * recording's size — a `Range: bytes=0-` for a 5 GB file allocates the
 * same as one for a 5 MB file.
 *
 * BOUNDED WINDOW. A browser's first media request asks for the whole
 * object (`bytes=0-`). Honoured literally, one PHP worker would trickle
 * bytes for the entire viewing session at the player's consumption
 * rate. So a ranged PLAYBACK response is capped at
 * `recordings.playback.max_range_bytes`: the 206 encloses that much,
 * Content-Range says so, and the player asks for the next window when
 * it needs it (RFC 9110 §15.3.7 requires only that Content-Range
 * describe what is enclosed). Worker occupancy per request is therefore
 * the transfer time of one window, not the length of the lesson.
 *
 * The admin DOWNLOAD is the deliberate exception: an attachment must
 * be the whole file, so that request does hold a worker for the full
 * transfer. Administrators are few; the limitation is documented.
 *
 * FAILS BEFORE HEADERS. The storage stream is opened before the
 * response is constructed, so a missing or unreadable object becomes a
 * clean 503 with a log entry rather than a truncated body behind a 200.
 *
 * Authorization is NOT here. Callers must have passed the relevant
 * RecordingPolicy ability (watch / download) first; this class trusts
 * them, exactly as RecordingStorage::read() trusts it. The locator
 * comes from the Recording row alone — no request input can select an
 * object.
 */
final class RecordingDeliveryService
{
    public function __construct(
        private readonly RecordingStorageResolver $storages,
    ) {}

    /**
     * @param  string|null  $rangeHeader  the request's Range header, verbatim
     * @param  bool  $inline  true for playback (inline), false for a download attachment
     * @param  bool  $headOnly  answer with headers only (HTTP HEAD)
     */
    public function respond(Recording $recording, ?string $rangeHeader, bool $inline, bool $headOnly = false): Response
    {
        $locator = RecordingLocator::fromRecording($recording);
        abort_if($locator === null, 404);

        $totalBytes = $recording->size_bytes;
        $disposition = sprintf('%s; filename="%s"', $inline ? 'inline' : 'attachment', $this->filename($recording));

        $headers = [
            'Content-Type' => $recording->mime_type ?? 'application/octet-stream',
            'Content-Disposition' => $disposition,
            // Private video must never be cached by a shared proxy.
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            // Only same-origin documents may embed this as a subresource
            // — a third-party page's <video src> gets nothing even if the
            // browser were to attach the session cookie.
            'Cross-Origin-Resource-Policy' => 'same-origin',
            // Seeking is a PLAYBACK feature and needs a known size to be
            // resolvable at all; the download is always the whole file.
            'Accept-Ranges' => $inline && $totalBytes !== null && $totalBytes > 0 ? 'bytes' : 'none',
        ];

        $range = $inline && $totalBytes !== null
            ? RecordingByteRange::fromHttpHeader($rangeHeader, $totalBytes)
            : null;

        if ($range === false) {
            return new Response('', Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE, [
                ...$headers,
                'Content-Range' => sprintf('bytes */%d', $totalBytes),
            ]);
        }

        if ($range !== null) {
            $range = $this->boundWindow($range);
            $headers['Content-Range'] = $range->contentRange($totalBytes);
            $headers['Content-Length'] = (string) $range->length();
            $status = Response::HTTP_PARTIAL_CONTENT;
        } else {
            if ($totalBytes !== null) {
                $headers['Content-Length'] = (string) $totalBytes;
            }
            $status = Response::HTTP_OK;
        }

        if ($headOnly) {
            return new Response('', $status, $headers);
        }

        try {
            $stream = $this->storages->forRecording($recording)->read($locator, $range);
        } catch (RecordingStorageException $e) {
            // Actionable for operators, not for the viewer: the row says
            // Available but the backend could not serve the object.
            Log::warning('Lesson recording object could not be opened for delivery.', [
                'recording_id' => $recording->getKey(),
                'failure_code' => $e->failureCode->value,
                'storage_driver' => $recording->storage_driver,
            ]);

            abort(503, 'This recording is temporarily unavailable.');
        }

        $limit = $range?->length();
        $chunkBytes = max(64 * 1024, (int) config('recordings.playback.chunk_bytes', 512 * 1024));

        return new StreamedResponse(function () use ($stream, $limit, $chunkBytes): void {
            try {
                $remaining = $limit;

                while (! feof($stream) && ($remaining === null || $remaining > 0)) {
                    if (connection_aborted()) {
                        break;
                    }

                    $chunk = fread($stream, $remaining === null ? $chunkBytes : min($chunkBytes, $remaining));

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    echo $chunk;

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();

                    if ($remaining !== null) {
                        $remaining -= strlen($chunk);
                    }
                }
            } finally {
                // Closing the socket is what tells the backend to stop
                // sending when the viewer has gone away.
                fclose($stream);
            }
        }, $status, $headers);
    }

    /** Caps a playback window at the configured maximum; see the class docblock. */
    private function boundWindow(RecordingByteRange $range): RecordingByteRange
    {
        $max = (int) config('recordings.playback.max_range_bytes', 8 * 1024 * 1024);

        if ($max <= 0 || $range->length() <= $max) {
            return $range;
        }

        return new RecordingByteRange($range->start, $range->start + $max - 1);
    }

    /**
     * The name the viewer's browser sees. Built from the booking's
     * public reference only — never a participant's name or email,
     * which would leak the other party's identity into a filename.
     * The extension comes from the recorded mime type, never from the
     * storage locator (a Drive locator is an opaque file id).
     */
    private function filename(Recording $recording): string
    {
        $reference = $recording->booking?->reference;
        $safe = is_string($reference) && preg_match('/^[A-Za-z0-9\-]{1,32}$/', $reference) === 1
            ? $reference
            : 'recording';

        $extension = RecordingStagingArea::extensionFor($recording->mime_type ?? '', 'recording.mp4');

        return sprintf('lesson-%s.%s', $safe, $extension);
    }
}
