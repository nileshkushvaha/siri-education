<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\GoogleDriveClient;
use App\Booking\DTOs\RecordingByteRange;
use App\Booking\DTOs\RecordingLocator;
use App\Booking\Storage\FilesystemRecordingStorage;
use App\Booking\Storage\GoogleDriveRecordingStorage;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Support\FakeGoogleDriveClient;
use Tests\TestCase;

/**
 * Seekable delivery lives or dies on the storage seam honouring a byte
 * window correctly. A wrong offset here is not a glitch — it is a
 * player that scrubs to the wrong moment or a browser that refuses to
 * play at all — so the window is verified at every layer: the parser
 * that turns a Range header into a window, the filesystem backend, and
 * the Drive backend's hand-off to its gateway.
 */
final class RecordingRangedReadTest extends TestCase
{
    use RefreshDatabase;

    private const string BYTES = '0123456789abcdefghijklmnopqrstuvwxyz';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    // ── Header parsing ────────────────────────────────────────────────

    public function test_a_missing_or_malformed_range_header_means_the_whole_object(): void
    {
        $this->assertNull(RecordingByteRange::fromHttpHeader(null, 100));
        $this->assertNull(RecordingByteRange::fromHttpHeader('', 100));
        $this->assertNull(RecordingByteRange::fromHttpHeader('items=0-5', 100));
        $this->assertNull(RecordingByteRange::fromHttpHeader('bytes=0-5,10-20', 100), 'multipart ranges are unsupported');
        $this->assertNull(RecordingByteRange::fromHttpHeader('bytes=-', 100));
        $this->assertNull(RecordingByteRange::fromHttpHeader('bytes=0-', 0), 'an object of unknown size cannot be ranged');
    }

    public function test_open_ended_suffix_and_bounded_ranges_resolve_against_the_total_size(): void
    {
        $open = RecordingByteRange::fromHttpHeader('bytes=10-', 100);
        $this->assertSame([10, 99], [$open->start, $open->end]);

        $bounded = RecordingByteRange::fromHttpHeader('bytes=10-19', 100);
        $this->assertSame([10, 19], [$bounded->start, $bounded->end]);
        $this->assertSame(10, $bounded->length());
        $this->assertSame('bytes 10-19/100', $bounded->contentRange(100));
        $this->assertSame('bytes=10-19', $bounded->toHttpHeader());

        $clamped = RecordingByteRange::fromHttpHeader('bytes=90-500', 100);
        $this->assertSame([90, 99], [$clamped->start, $clamped->end], 'an end past the object is clamped, per RFC 9110');

        $suffix = RecordingByteRange::fromHttpHeader('bytes=-5', 100);
        $this->assertSame([95, 99], [$suffix->start, $suffix->end]);

        $oversizedSuffix = RecordingByteRange::fromHttpHeader('bytes=-500', 100);
        $this->assertSame([0, 99], [$oversizedSuffix->start, $oversizedSuffix->end]);
    }

    public function test_an_unsatisfiable_range_is_reported_distinctly_from_no_range(): void
    {
        $this->assertFalse(RecordingByteRange::fromHttpHeader('bytes=100-', 100), 'start at the object size');
        $this->assertFalse(RecordingByteRange::fromHttpHeader('bytes=150-200', 100), 'start beyond the object');
        $this->assertFalse(RecordingByteRange::fromHttpHeader('bytes=20-10', 100), 'end before start');
        $this->assertFalse(RecordingByteRange::fromHttpHeader('bytes=-0', 100), 'an empty suffix');
    }

    public function test_a_window_can_never_be_negative_or_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RecordingByteRange(5, 4);
    }

    // ── Filesystem backend ────────────────────────────────────────────

    public function test_the_filesystem_backend_positions_the_stream_at_the_window_start(): void
    {
        Storage::disk('local')->put('recordings/2026/08/lesson-x.mp4', self::BYTES);
        $locator = new RecordingLocator(FilesystemRecordingStorage::KEY, 'recordings/2026/08/lesson-x.mp4');

        $stream = app(FilesystemRecordingStorage::class)->read($locator, new RecordingByteRange(10, 19));

        // The backend positions; the caller bounds the length it reads.
        $this->assertSame('abcdefghij', fread($stream, 10));
        fclose($stream);
    }

    public function test_the_filesystem_backend_still_serves_the_whole_object_without_a_window(): void
    {
        Storage::disk('local')->put('recordings/2026/08/lesson-y.mp4', self::BYTES);
        $locator = new RecordingLocator(FilesystemRecordingStorage::KEY, 'recordings/2026/08/lesson-y.mp4');

        $stream = app(FilesystemRecordingStorage::class)->read($locator);

        $this->assertSame(self::BYTES, stream_get_contents($stream));
        fclose($stream);
    }

    // ── Drive backend ─────────────────────────────────────────────────

    public function test_the_drive_backend_hands_the_window_to_its_gateway_instead_of_skipping_locally(): void
    {
        $client = new FakeGoogleDriveClient;
        $client->downloadBytes = self::BYTES;
        $this->app->instance(GoogleDriveClient::class, $client);

        $settings = app(MeetingSettings::class);
        $settings->platform_meeting_account = 'classes@example.test';
        $settings->google_credentials_json = Crypt::encryptString('{"client_email":"svc@example.iam.gserviceaccount.com","client_id":"1"}');
        $settings->recording_drive_root_folder_id = 'root-folder-id';
        $settings->save();

        $locator = new RecordingLocator(GoogleDriveRecordingStorage::KEY, 'drive-file-id');
        $range = new RecordingByteRange(26, 35);

        $stream = app(GoogleDriveRecordingStorage::class)->read($locator, $range);

        $this->assertSame('qrstuvwxyz', stream_get_contents($stream));
        $this->assertSame([$range], $client->readRanges, 'the window must reach Drive as a partial read, never as a full download');
        fclose($stream);
    }
}
