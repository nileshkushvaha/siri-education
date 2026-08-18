<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\DiscoveredRecording;
use App\Booking\DTOs\StagedRecordingFile;
use App\Booking\Exceptions\RecordingIngestionException;
use App\Models\BookingMeeting;

/**
 * An OPTIONAL refinement of MeetingRecordingProviderInterface for
 * providers that can say WHERE a recording is before handing it over.
 *
 * The base interface's fetchRecording() couples the two steps: by the
 * time the domain has a result, the bytes are already on local disk.
 * That is the only option for a provider that exposes recordings as a
 * download. It is the wrong option for Google Meet, whose recording is
 * already an object in Google Drive — the same service SIRI writes to
 * — where the transfer can happen entirely backend-side.
 *
 * Splitting the steps lets RecordingIngestionService decide:
 *
 *   discover  →  can the destination take this source natively?
 *                  yes → backend-side copy, no bytes through this host
 *                   no → stage() and use the normal streaming pipeline
 *
 * A provider implementing this MUST still implement fetchRecording()
 * (the base contract), and MUST NOT transfer anything during
 * discovery.
 */
interface DiscoversRecordingArtifacts
{
    /**
     * Locates the recording artifact for a finished meeting WITHOUT
     * transferring it.
     *
     * Null means "nothing to ingest yet" — the conference has not been
     * reconciled by the provider, or the recording file is still being
     * generated. That is an expected, transient state on the happy
     * path, not a failure: recording generation is asynchronous and
     * routinely lags the end of the class.
     *
     * @throws RecordingIngestionException on a classified provider failure
     */
    public function discoverRecording(BookingMeeting $meeting): ?DiscoveredRecording;

    /**
     * Downloads a previously discovered artifact to the private local
     * staging disk — the fallback used whenever a backend-side copy is
     * not available. MUST stream to a file and MUST NOT hold the
     * recording in memory.
     *
     * @throws RecordingIngestionException
     */
    public function stageRecording(DiscoveredRecording $discovered): StagedRecordingFile;
}
