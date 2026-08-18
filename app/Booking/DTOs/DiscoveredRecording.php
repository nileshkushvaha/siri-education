<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use Carbon\CarbonImmutable;

/**
 * A recording artifact that has been LOCATED at the meeting provider
 * but not yet transferred anywhere.
 *
 * Separating discovery from transfer is what makes a backend-native
 * copy possible: the pipeline learns where the artifact is, and only
 * then decides whether it needs to move bytes at all. A provider that
 * cannot offer this simply does not implement
 * DiscoversRecordingArtifacts and keeps staging as before.
 *
 * `providerReference` must be the provider's own IMMUTABLE identity
 * for this artifact — for Google Meet, the recording resource name
 * (`conferenceRecords/{c}/recordings/{r}`). It is what makes repeated
 * discovery of the same artifact recognisable as the same artifact,
 * rather than something matched on a title or a timestamp.
 *
 * `sizeBytes`/`mimeType` are what the provider claims. They are hints
 * used for safety limits before any transfer starts; what ends up on
 * the recording row is always what the destination backend actually
 * reports.
 */
final readonly class DiscoveredRecording
{
    public function __construct(
        public string $providerReference,
        public CarbonImmutable $recordedAt,
        public ?int $durationSeconds = null,
        public ?NativeRecordingSource $nativeSource = null,
        public ?int $sizeBytes = null,
        public ?string $mimeType = null,
        /**
         * How many legitimate artifacts the provider returned for this
         * conference. Greater than one means the lesson was recorded in
         * several sessions and SIRI is ingesting only the first — never
         * silently, see RecordingIngestionService.
         */
        public int $artifactCount = 1,
    ) {}
}
