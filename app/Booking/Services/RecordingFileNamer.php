<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\DTOs\StagedRecordingFile;
use App\Models\Recording;

/**
 * Builds the name a recording carries on an external storage backend.
 *
 * Two hard rules:
 *
 *  1. NO PII. Never a student or instructor name, email, phone, or
 *     any free-text subject a user could have influenced. The only
 *     identifier used is the booking's public reference (BK-XXXXXXXXXX),
 *     which is already the reference shown on invoices and meeting
 *     titles.
 *  2. The name is a LABEL, not an identity. Nothing ever looks a
 *     recording up by filename; the database row and its storage
 *     locator are the only identity. Renaming every file in the
 *     bucket would break nothing.
 *
 *     lesson-BK-7QF2M4XK1P-20260818-143000.mp4
 */
final class RecordingFileNamer
{
    public function displayName(Recording $recording, StagedRecordingFile $file): string
    {
        return $this->nameFor($recording, $file->extension());
    }

    /**
     * The same name for a recording that is never staged locally — a
     * backend-side copy has no local file to take an extension from,
     * so the caller supplies one derived from the provider's declared
     * content type.
     */
    public function displayNameForExtension(Recording $recording, string $extension): string
    {
        return $this->nameFor($recording, $extension);
    }

    private function nameFor(Recording $recording, string $extension): string
    {
        $reference = $recording->booking?->reference;
        $safeReference = is_string($reference) && preg_match('/^[A-Za-z0-9\-]{1,32}$/', $reference) === 1
            ? $reference
            // A missing/unexpected reference falls back to the recording's
            // own opaque uuid — never to anything user-supplied.
            : 'R'.substr(str_replace('-', '', (string) $recording->getKey()), 0, 12);

        $timestamp = ($recording->recorded_at ?? $recording->created_at ?? now())->format('Ymd-His');

        return sprintf('lesson-%s-%s.%s', $safeReference, $timestamp, $extension);
    }
}
