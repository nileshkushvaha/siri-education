<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;

/**
 * Upload fakes for media collections that validate the *detected* mime
 * type rather than a caller's hint.
 *
 * `UploadedFile::fake()->create('x.mp4', 512, 'video/mp4')` writes a
 * zero-byte file, so finfo reports `application/x-empty` and Media
 * Library rejects it — the declared mime is never consulted. Anything
 * uploading to UserProfile's `introduction_video` collection therefore
 * needs real bytes.
 */
final class FakeMediaFiles
{
    /**
     * Minimal bytes finfo genuinely detects as video/mp4 — an ISO base
     * media file `ftyp` box, padded so the file has a plausible size.
     */
    public static function mp4Bytes(): string
    {
        return "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 200);
    }

    public static function introductionVideo(string $name = 'introduction_video.mp4'): File
    {
        return UploadedFile::fake()->createWithContent($name, self::mp4Bytes());
    }
}
