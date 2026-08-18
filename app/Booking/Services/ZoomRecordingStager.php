<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\ZoomMeetingClient;
use App\Booking\DTOs\DiscoveredRecording;
use App\Booking\DTOs\StagedRecordingFile;
use App\Booking\Enums\RecordingFailureCode;
use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Exceptions\RecordingIngestionException;
use Throwable;

/**
 * Pulls one Zoom cloud-recording file onto the private staging disk.
 *
 * Unlike Google Meet — whose recording already sits in the Drive that
 * SIRI writes to, and can be copied backend-side — a Zoom recording
 * lives in Zoom's cloud. There is no shortcut: it must be downloaded
 * once and uploaded once. This class does the download half; the upload
 * and verification are the shared RecordingStorage pipeline, exactly as
 * for every other provider.
 *
 * Safety properties:
 *
 *  - STREAMED, never buffered. Fixed 1 MB chunks into a staged file, so
 *    a long class never occupies PHP memory. The size ceiling is
 *    enforced by RecordingStagingArea as bytes arrive, not trusted from
 *    a provider-declared Content-Length.
 *  - NO ARBITRARY URLs. The download URL comes from Zoom's own API for
 *    this lesson's own meeting, and ZoomApiClient re-validates that the
 *    host is Zoom before opening a connection. Nothing user- or
 *    database-controlled can steer it.
 *  - NO TOKEN LEAKAGE. The short-lived download credential is used
 *    inside the client and never persisted, returned, or logged.
 */
final class ZoomRecordingStager
{
    public function __construct(
        private readonly ZoomMeetingClient $client,
        private readonly RecordingStagingArea $staging,
    ) {}

    /**
     * @param  string|null  $downloadToken  Zoom's short-lived per-recording token when the
     *                                      artifact came from a webhook; null falls back to
     *                                      the account access token.
     *
     * @throws RecordingIngestionException
     */
    public function stage(DiscoveredRecording $discovered, ?string $downloadToken = null): StagedRecordingFile
    {
        $downloadUrl = $discovered->providerHandle;

        if (blank($downloadUrl)) {
            throw new RecordingIngestionException(
                RecordingFailureCode::SourceDownloadFailed,
                'Zoom recording has no download location.',
            );
        }

        $mimeType = $discovered->mimeType ?? 'video/mp4';

        try {
            $stream = $this->client->openRecordingStream($downloadUrl, $downloadToken);
        } catch (GatewayRequestException $e) {
            throw $this->translate($e);
        }

        try {
            return $this->staging->stageStream(
                function ($handle) use ($stream): void {
                    while (! feof($stream)) {
                        $chunk = fread($stream, 1024 * 1024);

                        if ($chunk === false) {
                            throw new RecordingIngestionException(
                                RecordingFailureCode::SourceDownloadFailed,
                                'Zoom recording download stream failed mid-transfer.',
                            );
                        }

                        fwrite($handle, $chunk);
                    }
                },
                sprintf('zoom-recording.%s', RecordingStagingArea::extensionFor($mimeType, 'recording.mp4')),
                $mimeType,
            );
        } catch (RecordingIngestionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RecordingIngestionException(
                RecordingFailureCode::SourceDownloadFailed,
                'Failed to stage the Zoom recording.',
                previous: $e,
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * A 401 here usually means the webhook's download token has aged
     * out (Zoom expires them in about a day) — transient, because the
     * reconciliation sweep re-fetches a fresh URL from the API.
     */
    private function translate(GatewayRequestException $e): RecordingIngestionException
    {
        $message = strtolower($e->getMessage());

        $code = match (true) {
            str_contains($message, 'non-zoom host') => RecordingFailureCode::SourceRejected,
            str_contains($message, 'http 401'),
            str_contains($message, 'http 403') => RecordingFailureCode::SourceAccessDenied,
            str_contains($message, 'http 404'),
            str_contains($message, 'http 410') => RecordingFailureCode::SourceExpired,
            str_contains($message, 'http 429') => RecordingFailureCode::SourceRateLimited,
            default => RecordingFailureCode::SourceDownloadFailed,
        };

        return new RecordingIngestionException($code, $e->getMessage(), previous: $e);
    }
}
