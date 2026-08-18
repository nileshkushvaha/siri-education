<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\RecordingStorage;
use App\Booking\Contracts\SupportsNativeIngestion;
use App\Booking\DTOs\NativeRecordingSource;
use App\Booking\DTOs\RecordingLocator;
use App\Booking\DTOs\RecordingStorageRequest;
use App\Booking\Exceptions\RecordingStorageException;
use App\Booking\Services\RecordingStagingArea;
use App\Booking\Storage\FilesystemRecordingStorage;
use App\Booking\Storage\GoogleDriveRecordingStorage;
use App\Booking\Storage\RecordingStorageResolver;
use App\Models\Recording;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InMemoryRecordingStorage;
use Tests\TestCase;

/**
 * The migration guarantee, tested rather than asserted in a docblock.
 *
 * Two claims are made about this architecture:
 *
 *   1. Switching the storage backend — including to Amazon S3 — is a
 *      configuration change, not a code change. The filesystem driver
 *      is exercised here against a fake disk; pointing it at the "s3"
 *      disk is the same class with a different disk name.
 *   2. Recordings written under an OLD backend stay readable and
 *      deletable after the configured default has moved on, because
 *      each row remembers its own driver.
 *
 * Both are verified below. The domain-level proof — that the whole
 * ingestion pipeline runs against a storage implementation that
 * imports no vendor type at all — lives in RecordingIngestionTest.
 */
final class RecordingStoragePortabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app(RecordingStagingArea::class)->path());

        parent::tearDown();
    }

    private function stagedRequest(string $name = 'lesson-BK-TEST123-20260818-120000.mp4'): RecordingStorageRequest
    {
        $bytes = "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 200);

        return new RecordingStorageRequest(
            file: app(RecordingStagingArea::class)->stageContents($bytes, 'lesson.mp4'),
            displayName: $name,
            partitionedAt: CarbonImmutable::parse('2026-08-18 12:00:00'),
        );
    }

    // ── The contract, exercised end to end on a real disk ─────────────

    public function test_the_filesystem_driver_round_trips_a_recording_through_the_contract(): void
    {
        /** @var RecordingStorage $storage */
        $storage = app(FilesystemRecordingStorage::class);
        $request = $this->stagedRequest();

        $stored = $storage->put($request);

        $this->assertSame(FilesystemRecordingStorage::KEY, $stored->locator->driver);
        $this->assertSame($request->file->sizeBytes, $stored->remoteSizeBytes);

        // Verification passes for what we actually wrote…
        $storage->verify($stored->locator, $request->file->sizeBytes);

        // …and the content comes back byte-identical through read().
        $stream = $storage->read($stored->locator);
        $this->assertSame(file_get_contents($request->file->absolutePath), stream_get_contents($stream));
        fclose($stream);

        $storage->delete($stored->locator);
        Storage::disk('local')->assertMissing($stored->locator->path);
    }

    public function test_the_filesystem_driver_partitions_objects_by_year_and_month(): void
    {
        $stored = app(FilesystemRecordingStorage::class)->put($this->stagedRequest());

        $this->assertStringStartsWith('recordings/2026/08/', $stored->locator->path);
    }

    public function test_verification_fails_when_the_stored_object_size_does_not_match(): void
    {
        $storage = app(FilesystemRecordingStorage::class);
        $stored = $storage->put($this->stagedRequest());

        $this->expectException(RecordingStorageException::class);

        $storage->verify($stored->locator, 999_999);
    }

    public function test_verification_fails_when_the_object_is_gone(): void
    {
        $storage = app(FilesystemRecordingStorage::class);
        $stored = $storage->put($this->stagedRequest());
        Storage::disk('local')->delete($stored->locator->path);

        $this->expectException(RecordingStorageException::class);

        $storage->verify($stored->locator, 100);
    }

    /** Retention re-runs must not fail on work a previous run already did. */
    public function test_deleting_an_already_absent_object_succeeds(): void
    {
        $storage = app(FilesystemRecordingStorage::class);

        $storage->delete(new RecordingLocator(FilesystemRecordingStorage::KEY, 'recordings/2026/08/never-existed.mp4'));

        $this->addToAssertionCount(1);
    }

    // ── Fail closed ───────────────────────────────────────────────────

    /**
     * A public disk would hand out unauthenticated URLs to private
     * student video, so it is treated as no configuration at all
     * rather than quietly working.
     */
    public function test_a_public_disk_is_never_accepted_as_recording_storage(): void
    {
        config(['recordings.filesystem.disk' => 'public']);

        $this->assertFalse(app(FilesystemRecordingStorage::class)->isConfigured());
    }

    public function test_resolving_an_unconfigured_default_driver_fails_closed(): void
    {
        config(['recordings.storage_driver' => 'google_drive']);
        // No Drive root folder / credentials configured.

        $this->expectException(RecordingStorageException::class);

        app(RecordingStorageResolver::class)->default();
    }

    public function test_resolving_an_unknown_driver_fails_closed(): void
    {
        config(['recordings.storage_driver' => 'dropbox']);

        $this->expectException(RecordingStorageException::class);

        app(RecordingStorageResolver::class)->default();
    }

    /**
     * Missing recording credentials must never stop the application
     * from serving requests — recording is an optional feature.
     */
    public function test_an_unconfigured_backend_does_not_prevent_the_application_from_booting(): void
    {
        config(['recordings.storage_driver' => 'google_drive']);

        $this->get(route('home'))->assertSuccessful();
    }

    // ── The S3 migration seam ─────────────────────────────────────────

    /**
     * THE MIGRATION GUARANTEE. Once the configured default has moved
     * to a different backend, an existing recording still resolves to
     * the backend that actually holds its bytes — so no backfill,
     * cutover window, or domain change is required to switch to S3.
     */
    public function test_an_existing_recording_still_resolves_to_its_original_backend_after_the_default_changes(): void
    {
        $recording = Recording::factory()->available()->create([
            'storage_driver' => FilesystemRecordingStorage::KEY,
        ]);

        // The platform has since migrated its default to another backend.
        config([
            'recordings.storage_driver' => InMemoryRecordingStorage::KEY,
            'recordings.drivers' => [
                FilesystemRecordingStorage::KEY => FilesystemRecordingStorage::class,
                InMemoryRecordingStorage::KEY => InMemoryRecordingStorage::class,
            ],
        ]);

        $resolver = app(RecordingStorageResolver::class);

        $this->assertInstanceOf(InMemoryRecordingStorage::class, $resolver->default());
        $this->assertInstanceOf(FilesystemRecordingStorage::class, $resolver->forRecording($recording));
    }

    /**
     * Amazon S3 needs no new adapter: it is the SAME filesystem driver
     * pointed at the existing "s3" disk. This asserts the wiring, not
     * AWS itself — the disk is faked.
     */
    public function test_the_filesystem_driver_targets_the_s3_disk_purely_by_configuration(): void
    {
        Storage::fake('s3');
        config(['recordings.filesystem.disk' => 's3']);

        $storage = app(FilesystemRecordingStorage::class);
        $this->assertTrue($storage->isConfigured());

        $stored = $storage->put($this->stagedRequest());

        Storage::disk('s3')->assertExists($stored->locator->path);
        // Same driver key, so the domain sees no difference whatsoever.
        $this->assertSame(FilesystemRecordingStorage::KEY, $stored->locator->driver);
    }

    // ── Backend isolation ─────────────────────────────────────────────

    /**
     * The architectural rule, enforced mechanically: no Google type may
     * appear anywhere in the recording domain. Only the Drive adapter
     * and its SDK gateway are permitted to reference the SDK, and only
     * the adapter may name a Drive-specific concept.
     *
     * Comments are stripped before matching — these files deliberately
     * DISCUSS what they must not depend on, and a docblock explaining
     * why `google_drive_file_id` does not exist is not a dependency.
     */
    public function test_no_google_specific_type_leaks_into_the_recording_domain(): void
    {
        $domainFiles = array_merge(
            glob(app_path('Booking/Services/Recording*.php')) ?: [],
            glob(app_path('Booking/Contracts/RecordingStorage.php')) ?: [],
            glob(app_path('Booking/DTOs/Recording*.php')) ?: [],
            glob(app_path('Booking/DTOs/StagedRecordingFile.php')) ?: [],
            glob(app_path('Booking/DTOs/StoredRecording.php')) ?: [],
            glob(app_path('Booking/Jobs/CaptureLessonRecordingJob.php')) ?: [],
            glob(app_path('Models/Recording.php')) ?: [],
            glob(app_path('Policies/RecordingPolicy.php')) ?: [],
            glob(app_path('Http/Controllers/Dashboard/RecordingDownloadController.php')) ?: [],
        );

        $this->assertNotEmpty($domainFiles);

        foreach ($domainFiles as $file) {
            $source = php_strip_whitespace($file);

            foreach ([
                'use Google\\',
                'GoogleDriveClient',
                'GoogleMeetClient',
                'drive.google.com',
                'google_drive_file_id',
                'md5Checksum',
                // Meet's own vocabulary must not reach the domain either.
                'conferenceRecords',
                'driveDestination',
                'FILE_GENERATED',
            ] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    sprintf('%s must not depend on Google-specific type/identifier [%s]', basename($file), $forbidden),
                );
            }
        }
    }

    /**
     * Each Google SDK is reachable from exactly ONE gateway file.
     * Comments stripped, as above.
     *
     * Meet is included because recording ACQUISITION added a second
     * Google surface: the guarantee is not "Drive is isolated" but
     * "every Google SDK is isolated", so a Meet type leaking into a
     * service or a controller fails here just as loudly.
     */
    public static function googleSdkProvider(): array
    {
        return [
            'Drive' => [['Google\Service\Drive', 'Google\Http\MediaFileUpload'], 'Booking/Gateways/GoogleDriveSdkClient.php'],
            'Meet' => [['Google\Service\Meet'], 'Booking/Gateways/GoogleMeetSdkClient.php'],
            'Calendar' => [['Google\Service\Calendar'], 'Booking/Gateways/GoogleCalendarSdkClient.php'],
        ];
    }

    /**
     * @param  list<string>  $sdkTypes
     */
    #[DataProvider('googleSdkProvider')]
    public function test_each_google_sdk_is_touched_by_exactly_one_gateway(array $sdkTypes, string $expectedFile): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $source = php_strip_whitespace($file->getPathname());

            foreach ($sdkTypes as $sdkType) {
                if (str_contains($source, $sdkType)) {
                    $offenders[] = $file->getRelativePathname();

                    break;
                }
            }
        }

        $this->assertSame([$expectedFile], $offenders);
    }

    /**
     * The Drive-native copy optimization must not have quietly become
     * a dependency. A backend that cannot ingest natively — every
     * backend except Drive, S3 included — simply does not implement
     * the optional capability, and the pipeline streams instead.
     */
    public function test_only_the_drive_backend_claims_native_ingestion(): void
    {
        $this->assertInstanceOf(SupportsNativeIngestion::class, app(GoogleDriveRecordingStorage::class));
        $this->assertNotInstanceOf(SupportsNativeIngestion::class, app(FilesystemRecordingStorage::class));
    }

    /**
     * THE S3 QUESTION, asked of the native path specifically: a Meet
     * recording sitting in Google Drive is a Drive-native source, but
     * once storage is a filesystem disk (local now, s3 later) nothing
     * can take it natively — so it must fall back rather than fail.
     */
    public function test_a_drive_native_source_is_not_ingestible_by_the_filesystem_backend(): void
    {
        $storage = app(FilesystemRecordingStorage::class);
        $driveSource = new NativeRecordingSource(GoogleDriveRecordingStorage::KEY, 'meet-drive-file-1');

        // The filesystem backend cannot even be asked — it does not
        // implement the capability, which is exactly the fallback
        // condition RecordingIngestionService checks.
        $this->assertNotInstanceOf(SupportsNativeIngestion::class, $storage);

        // And Drive itself declines a source from another backend.
        $this->assertFalse(
            app(GoogleDriveRecordingStorage::class)->canIngestNatively(new NativeRecordingSource('s3', 'some-key')),
        );
        $this->assertNotSame('', $driveSource->reference);
    }

    public function test_the_drive_adapter_is_registered_as_an_available_driver(): void
    {
        $this->assertContains(
            GoogleDriveRecordingStorage::KEY,
            app(RecordingStorageResolver::class)->availableDrivers(),
        );
    }
}
