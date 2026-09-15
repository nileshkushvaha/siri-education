<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\ResolvedExternalSource;
use App\Booking\Exceptions\RecordingStorageException;

/**
 * Optional capability of a RecordingStorage: turn an operator-supplied
 * reference (whatever a person can paste — an id, a link) into a
 * source the same backend can ingest natively, proving on the way that
 * the platform can actually read it and that it is a recording.
 *
 * This is the seam behind the admin's "attach a recording" recovery
 * action. Only a backend that can both read foreign objects and copy
 * them into its own recording area implements it; the action hides
 * itself on any other backend. The reference is parsed HERE and nowhere
 * else — nothing above the adapter knows what shape it has, and the
 * resolved source carries only the opaque handle native ingestion
 * already understands.
 *
 * Implementations must never log or echo the reference, and must
 * refuse (not guess) anything they cannot see, anything in the trash,
 * and anything outside the configured recording content types.
 */
interface AcceptsExternalSources
{
    /**
     * @throws RecordingStorageException with ExternalSourceInaccessible
     *                                   or ExternalSourceUnsupported
     */
    public function resolveExternalSource(string $operatorReference): ResolvedExternalSource;
}
