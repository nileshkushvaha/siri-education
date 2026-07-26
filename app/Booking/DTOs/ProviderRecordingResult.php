<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use Carbon\CarbonImmutable;

/**
 * What a MeetingRecordingProviderInterface::fetchRecording() call
 * returns once the provider's recording is actually ready. $content
 * is the raw file content (already fetched into memory/temp storage by
 * the provider adapter) — RecordingService only ever writes bytes it
 * already has into our own private Media Library disk; it never streams
 * from or trusts a provider-hosted URL as a "download".
 */
final readonly class ProviderRecordingResult
{
    public function __construct(
        public string $providerReference,
        public string $content,
        public string $filename,
        public string $mimeType,
        public ?int $durationSeconds,
        public CarbonImmutable $recordedAt,
    ) {}
}
