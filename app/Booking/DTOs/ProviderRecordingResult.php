<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use Carbon\CarbonImmutable;

/**
 * What a MeetingRecordingProviderInterface::fetchRecording() call
 * returns once the provider's recording is actually ready.
 *
 * The payload is a StagedRecordingFile — a file already written to the
 * local private staging disk by the provider adapter — never a string
 * of bytes and never a provider URL. Two consequences, both
 * deliberate:
 *
 *  - a multi-gigabyte class video never occupies PHP memory; and
 *  - the domain never receives, stores, or follows a provider-hosted
 *    download link, so a database value can never become an arbitrary
 *    server-side fetch target. Fetching happens only inside a trusted
 *    provider integration, against that provider's own API.
 */
final readonly class ProviderRecordingResult
{
    public function __construct(
        public string $providerReference,
        public StagedRecordingFile $file,
        public ?int $durationSeconds,
        public CarbonImmutable $recordedAt,
    ) {}
}
