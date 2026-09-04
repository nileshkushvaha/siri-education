<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\IntroductionVideoFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The acceptance predicate is shared by validation and the media
 * collection. In the Livewire test harness a temporary upload's MIME type
 * is derived from its NAME, so content-based refusals (a JPEG renamed
 * .mp4) can only be pinned here, against the predicate itself.
 */
final class IntroductionVideoFileTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, bool}> */
    public static function cases(): iterable
    {
        yield 'mp4 detected as video/mp4' => ['mp4', 'video/mp4', true];
        yield 'mp4 detected as x-m4v (common libmagic answer)' => ['mp4', 'video/x-m4v', true];
        yield 'mp4 detected as application/mp4' => ['mp4', 'application/mp4', true];
        yield 'mp4 libmagic could not identify' => ['mp4', 'application/octet-stream', true];
        yield 'mp4 with no detection at all' => ['mp4', null, true];
        yield 'mov detected as quicktime' => ['mov', 'video/quicktime', true];
        yield 'webm' => ['webm', 'video/webm', true];
        yield 'case-insensitive extension and mime' => ['MP4', 'Video/MP4', true];
        yield 'mime with a charset suffix' => ['webm', 'video/webm; charset=binary', true];
        yield 'jpeg renamed to mp4 is refused' => ['mp4', 'image/jpeg', false];
        yield 'pdf renamed to mp4 is refused' => ['mp4', 'application/pdf', false];
        yield 'real video with a non-video extension is refused' => ['pdf', 'video/mp4', false];
        yield 'avi is not served' => ['avi', 'video/x-msvideo', false];
        yield 'empty extension is refused' => ['', 'video/mp4', false];
    }

    #[DataProvider('cases')]
    public function test_accepts(string $extension, ?string $mime, bool $expected): void
    {
        $this->assertSame($expected, IntroductionVideoFile::accepts($extension, $mime));
    }
}
