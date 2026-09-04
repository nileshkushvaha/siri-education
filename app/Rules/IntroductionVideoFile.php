<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Accepts an instructor's introduction video by BOTH the file name's
 * extension and the server-detected content type.
 *
 * Why not `mimes:mp4,webm,quicktime`: that rule ignores the name and trusts
 * only libmagic's guess, and libmagic is unreliable for MP4 — depending on
 * the brand atoms and the `file` database version it answers video/mp4,
 * video/x-m4v, application/mp4 or plain application/octet-stream. A real,
 * playable MP4 was refused on staging with "must be a file of type: mp4,
 * webm, quicktime" while the same file passed locally. (`quicktime` is
 * also not an extension, so `.mov` never matched at all.)
 *
 * Rule: the extension must be a video extension we serve, and the detected
 * type must either be a video type or one of the "could not tell" answers.
 * A JPEG renamed to .mp4 is still refused (detected image/jpeg); a PDF named
 * .mp4 is refused (application/pdf). What we stop insisting on is libmagic
 * recognising every MP4 flavour.
 */
final class IntroductionVideoFile implements ValidationRule
{
    /** @var list<string> */
    public const array EXTENSIONS = ['mp4', 'm4v', 'webm', 'mov'];

    /** @var list<string> */
    public const array VIDEO_MIME_TYPES = [
        'video/mp4', 'video/x-m4v', 'video/mp4v-es', 'application/mp4',
        'video/webm',
        'video/quicktime',
    ];

    /** Detected types that mean "libmagic could not identify this", not "this is not a video". */
    public const array UNIDENTIFIED_MIME_TYPES = ['application/octet-stream', 'binary/octet-stream', 'application/x-empty', 'inode/x-empty'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('The :attribute must be an uploaded video file.');

            return;
        }

        $extension = Str::lower((string) ($value->getClientOriginalExtension() ?: $value->extension()));
        $mime = self::detectedMime($value);

        if (! self::accepts($extension, $mime)) {
            Log::info('Introduction video rejected by validation.', [
                'client_name' => $value->getClientOriginalName(),
                'client_extension' => $extension,
                'detected_mime' => $mime,
                'size' => $value->getSize(),
            ]);

            $fail('The :attribute must be an MP4, WebM or MOV video.');
        }
    }

    /**
     * Shared with the UserProfile media collection so validation and
     * storage can never disagree about what a video is.
     */
    public static function accepts(?string $extension, ?string $mime): bool
    {
        $extension = Str::lower((string) $extension);
        $mime = $mime !== null ? Str::lower(Str::before($mime, ';')) : null;

        if (! in_array($extension, self::EXTENSIONS, true)) {
            return false;
        }

        return $mime === null
            || $mime === ''
            || in_array($mime, self::VIDEO_MIME_TYPES, true)
            || in_array($mime, self::UNIDENTIFIED_MIME_TYPES, true);
    }

    private static function detectedMime(UploadedFile $file): ?string
    {
        try {
            return $file->getMimeType();
        } catch (\Throwable) {
            return null;
        }
    }
}
