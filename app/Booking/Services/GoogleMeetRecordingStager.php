<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\GoogleDriveClient;
use App\Booking\DTOs\DiscoveredRecording;
use App\Booking\DTOs\GoogleDriveTarget;
use App\Booking\DTOs\StagedRecordingFile;
use App\Booking\Enums\RecordingFailureCode;
use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Exceptions\RecordingIngestionException;
use App\Booking\Storage\GoogleDriveRecordingStorage;
use App\Settings\MeetingSettings;
use Throwable;

/**
 * The FALLBACK half of Google Meet recording acquisition: pulling the
 * Meet-generated MP4 out of Drive onto local disk.
 *
 * Normally this never runs. When SIRI's storage is Google Drive, the
 * recording is copied backend-side and no bytes touch this host. This
 * path exists for the cases where that is impossible:
 *
 *  - storage has moved to S3 (or any non-Drive backend), so a
 *    server-side copy is meaningless; or
 *  - Drive declined the copy and ingestion degraded to streaming.
 *
 * Either way the download is streamed chunk-by-chunk into the private
 * staging area, never assembled in memory, and the size ceiling is
 * enforced by RecordingStagingArea as the bytes arrive rather than
 * trusted from a provider-declared Content-Length.
 *
 * The source is always a Drive FILE ID that came from the Meet API for
 * this lesson's own conference — never a URL from the database, and
 * never anything a user can influence, so this can never become an
 * arbitrary server-side fetcher.
 */
final class GoogleMeetRecordingStager
{
    public function __construct(
        private readonly GoogleDriveClient $drive,
        private readonly RecordingStagingArea $staging,
        private readonly MeetingSettings $settings,
    ) {}

    /**
     * @throws RecordingIngestionException
     */
    public function stage(DiscoveredRecording $discovered): StagedRecordingFile
    {
        $source = $discovered->nativeSource;

        if ($source === null || $source->driver !== GoogleDriveRecordingStorage::KEY || $source->reference === '') {
            throw new RecordingIngestionException(
                RecordingFailureCode::SourceDownloadFailed,
                'Google Meet recording has no Drive artifact to download.',
            );
        }

        $target = $this->target();
        $mimeType = $discovered->mimeType ?? 'video/mp4';

        try {
            $stream = $this->drive->openReadStream($target, $source->reference);
        } catch (GatewayRequestException $e) {
            throw $this->translate($e);
        }

        try {
            return $this->staging->stageStream(
                function ($handle) use ($stream): void {
                    // Fixed-size chunks: a multi-gigabyte class video
                    // is never materialized in PHP memory.
                    while (! feof($stream)) {
                        $chunk = fread($stream, 1024 * 1024);

                        if ($chunk === false) {
                            throw new RecordingIngestionException(
                                RecordingFailureCode::SourceDownloadFailed,
                                'Google Drive download stream failed mid-transfer.',
                            );
                        }

                        fwrite($handle, $chunk);
                    }
                },
                sprintf('meet-recording.%s', RecordingStagingArea::extensionFor($mimeType, 'recording.mp4')),
                $mimeType,
            );
        } catch (RecordingIngestionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RecordingIngestionException(
                RecordingFailureCode::SourceDownloadFailed,
                'Failed to stage the Google Meet recording from Drive.',
                previous: $e,
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Reuses the same Workspace service account, impersonated subject
     * and Shared Drive configuration as recording STORAGE — one Google
     * identity for the whole feature, never a second auth system.
     */
    private function target(): GoogleDriveTarget
    {
        $credentials = $this->settings->decryptedGoogleCredentials();
        $subject = $this->settings->platform_meeting_account;

        if ($credentials === null || blank($subject)) {
            throw new RecordingIngestionException(
                RecordingFailureCode::StorageNotConfigured,
                'Google credentials or platform account are not configured.',
            );
        }

        return new GoogleDriveTarget(
            credentialsJson: $credentials,
            delegatedSubject: $subject,
            sharedDriveId: $this->settings->recording_drive_shared_drive_id,
        );
    }

    /**
     * A 403 here almost always means the drive.meet.readonly scope is
     * missing from the domain-wide delegation grant — an operator fix,
     * so transient rather than permanent.
     */
    private function translate(GatewayRequestException $e): RecordingIngestionException
    {
        $message = strtolower($e->getMessage());

        $code = match (true) {
            str_contains($message, 'http 403'),
            str_contains($message, 'forbidden'),
            str_contains($message, 'insufficient') => RecordingFailureCode::SourceAccessDenied,
            str_contains($message, 'http 404'),
            str_contains($message, 'not found') => RecordingFailureCode::SourceExpired,
            str_contains($message, 'http 429'),
            str_contains($message, 'rate limit') => RecordingFailureCode::SourceRateLimited,
            default => RecordingFailureCode::SourceDownloadFailed,
        };

        return new RecordingIngestionException($code, $e->getMessage(), previous: $e);
    }
}
