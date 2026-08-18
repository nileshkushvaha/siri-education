<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\GoogleDriveClient;
use App\Booking\DTOs\RecordingLocator;
use App\Booking\DTOs\RecordingStorageRequest;
use App\Booking\Enums\RecordingFailureCode;
use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Exceptions\RecordingStorageException;
use App\Booking\Gateways\GoogleDriveSdkClient;
use App\Booking\Services\RecordingStagingArea;
use App\Booking\Storage\GoogleDriveRecordingStorage;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeGoogleDriveClient;
use Tests\TestCase;

/**
 * The Google Drive adapter, against a fake GoogleDriveClient — no
 * network, no SDK, no credentials. The SDK itself is already isolated
 * behind that contract (the same pattern GoogleCalendarClient uses),
 * so what matters here is the adapter's own behaviour: folder
 * strategy, PII-free naming, honest verification, and — most
 * importantly — that every Google failure is translated into the
 * domain's failure vocabulary rather than leaking upward.
 */
final class GoogleDriveRecordingStorageTest extends TestCase
{
    use RefreshDatabase;

    private FakeGoogleDriveClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new FakeGoogleDriveClient;
        $this->app->instance(GoogleDriveClient::class, $this->client);

        $settings = app(MeetingSettings::class);
        $settings->platform_meeting_account = 'classes@example.test';
        $settings->google_credentials_json = Crypt::encryptString('{"client_email":"svc@example.iam.gserviceaccount.com","client_id":"1"}');
        $settings->recording_drive_root_folder_id = 'root-folder-id';
        $settings->recording_drive_shared_drive_id = null;
        $settings->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app(RecordingStagingArea::class)->path());

        parent::tearDown();
    }

    private function storage(): GoogleDriveRecordingStorage
    {
        return app(GoogleDriveRecordingStorage::class);
    }

    private function request(string $name = 'lesson-BK-ABC123-20260818-120000.mp4'): RecordingStorageRequest
    {
        $bytes = "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 200);

        return new RecordingStorageRequest(
            file: app(RecordingStagingArea::class)->stageContents($bytes, 'lesson.mp4'),
            displayName: $name,
            partitionedAt: CarbonImmutable::parse('2026-08-18 12:00:00'),
        );
    }

    // ── Configuration ─────────────────────────────────────────────────

    public function test_the_adapter_is_configured_when_credentials_account_and_root_folder_are_present(): void
    {
        $this->assertTrue($this->storage()->isConfigured());
    }

    public function test_the_adapter_is_not_configured_without_a_root_folder(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->recording_drive_root_folder_id = null;
        $settings->save();

        $this->assertFalse($this->storage()->isConfigured());
    }

    /** isConfigured() must never perform I/O — it is called on hot paths and at fail-closed checks. */
    public function test_checking_configuration_makes_no_api_calls(): void
    {
        $this->storage()->isConfigured();

        $this->assertSame([], $this->client->calls);
    }

    // ── Folder strategy ───────────────────────────────────────────────

    public function test_recordings_are_filed_under_a_year_and_month_folder_beneath_the_configured_root(): void
    {
        $this->storage()->put($this->request());

        $this->assertSame(
            [['parent' => 'root-folder-id', 'name' => '2026'], ['parent' => 'folder:2026', 'name' => '08']],
            $this->client->folderLookups,
        );
        $this->assertSame('folder:08', $this->client->uploads[0]['parent']);
    }

    /**
     * Folder names are a partition, never a directory of people —
     * putting a student's name in Drive would both leak PII and invite
     * treating the folder tree as a second source of truth.
     */
    public function test_folder_names_contain_no_participant_information(): void
    {
        $this->storage()->put($this->request());

        foreach ($this->client->folderLookups as $lookup) {
            $this->assertMatchesRegularExpression('/^\d{2,4}$/', $lookup['name']);
        }
    }

    public function test_the_uploaded_file_keeps_the_pii_free_display_name(): void
    {
        $this->storage()->put($this->request('lesson-BK-ABC123-20260818-120000.mp4'));

        $this->assertSame('lesson-BK-ABC123-20260818-120000.mp4', $this->client->uploads[0]['filename']);
    }

    // ── Upload ────────────────────────────────────────────────────────

    public function test_a_successful_upload_returns_a_drive_locator(): void
    {
        $stored = $this->storage()->put($this->request());

        $this->assertSame(GoogleDriveRecordingStorage::KEY, $stored->locator->driver);
        $this->assertSame('file-1', $stored->locator->path);
        $this->assertNotNull($stored->remoteSizeBytes);
    }

    /** Large recordings must go up resumably and chunked, never in one shot. */
    public function test_uploads_use_a_chunked_resumable_transfer(): void
    {
        config(['recordings.google_drive.chunk_bytes' => 4 * 1024 * 1024]);

        $this->storage()->put($this->request());

        $this->assertSame(4 * 1024 * 1024, $this->client->uploads[0]['chunkBytes']);
        $this->assertContains('uploadResumable', $this->client->calls);
    }

    // ── Verification ──────────────────────────────────────────────────

    public function test_verification_passes_when_drive_reports_the_expected_size(): void
    {
        $stored = $this->storage()->put($this->request());

        $this->storage()->verify($stored->locator, $this->client->uploads[0]['size']);

        $this->addToAssertionCount(1);
    }

    public function test_verification_fails_when_drive_reports_a_different_size(): void
    {
        $stored = $this->storage()->put($this->request());

        $this->expectException(RecordingStorageException::class);

        $this->storage()->verify($stored->locator, 999_999);
    }

    public function test_verification_fails_when_the_file_is_missing_from_drive(): void
    {
        $this->client->files = [];

        try {
            $this->storage()->verify(new RecordingLocator(GoogleDriveRecordingStorage::KEY, 'file-1'), 100);
            $this->fail('missing Drive file should fail verification');
        } catch (RecordingStorageException $e) {
            $this->assertSame(RecordingFailureCode::StorageVerificationFailed, $e->failureCode);
        }
    }

    /** A trashed file still "exists" to the API but is not a durable recording. */
    public function test_verification_fails_when_the_file_is_in_the_drive_trash(): void
    {
        $stored = $this->storage()->put($this->request());
        $this->client->files['file-1']['trashed'] = true;

        $this->expectException(RecordingStorageException::class);

        $this->storage()->verify($stored->locator, $this->client->uploads[0]['size']);
    }

    /** Verification is metadata-only: re-downloading every video to prove it arrived would double transfer cost. */
    public function test_verification_never_downloads_the_recording_back(): void
    {
        $stored = $this->storage()->put($this->request());
        $this->client->calls = [];

        $this->storage()->verify($stored->locator, $this->client->uploads[0]['size']);

        $this->assertNotContains('openReadStream', $this->client->calls);
    }

    // ── Failure translation ───────────────────────────────────────────

    public function test_a_quota_failure_is_translated_to_the_quota_failure_code(): void
    {
        $this->client->throwOnUpload = new GatewayRequestException('Google Drive API error (HTTP 403, reason: storageQuotaExceeded): The user has exceeded their Drive storage quota.');

        try {
            $this->storage()->put($this->request());
            $this->fail('expected a storage exception');
        } catch (RecordingStorageException $e) {
            $this->assertSame(RecordingFailureCode::StorageQuotaExceeded, $e->failureCode);
            // Transient: an operator frees space and the retry succeeds.
            $this->assertFalse($e->failureCode->isPermanent());
        }
    }

    public function test_an_authentication_failure_is_translated_to_the_auth_failure_code(): void
    {
        $this->client->throwOnUpload = new GatewayRequestException('Google OAuth token error: unauthorized_client. Delegated subject: classes@example.test');

        try {
            $this->storage()->put($this->request());
            $this->fail('expected a storage exception');
        } catch (RecordingStorageException $e) {
            $this->assertSame(RecordingFailureCode::StorageAuthFailed, $e->failureCode);
        }
    }

    public function test_an_unclassified_drive_failure_falls_back_to_a_retryable_upload_failure(): void
    {
        $this->client->throwOnUpload = new GatewayRequestException('Google Drive API error (HTTP 500, reason: backendError): Internal error.');

        try {
            $this->storage()->put($this->request());
            $this->fail('expected a storage exception');
        } catch (RecordingStorageException $e) {
            $this->assertSame(RecordingFailureCode::StorageUploadFailed, $e->failureCode);
            $this->assertFalse($e->failureCode->isPermanent());
        }
    }

    /** Callers above the adapter must never see a Google exception type. */
    public function test_no_google_exception_type_escapes_the_adapter(): void
    {
        $this->client->throwOnUpload = new GatewayRequestException('anything at all');

        $this->expectException(RecordingStorageException::class);

        $this->storage()->put($this->request());
    }

    // ── Shared Drive ──────────────────────────────────────────────────

    public function test_a_configured_shared_drive_id_is_passed_through_to_every_call(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->recording_drive_shared_drive_id = 'shared-drive-1';
        $settings->save();

        $this->storage()->put($this->request());

        $this->assertSame('shared-drive-1', $this->client->lastTarget?->sharedDriveId);
        $this->assertTrue($this->client->lastTarget?->usesSharedDrive());
    }

    // ── Deletion ──────────────────────────────────────────────────────

    public function test_deleting_removes_the_drive_file(): void
    {
        $stored = $this->storage()->put($this->request());

        $this->storage()->delete($stored->locator);

        $this->assertSame(['file-1'], $this->client->deleted);
    }

    // ── Access model ──────────────────────────────────────────────────

    /**
     * The single most important security property of this adapter: it
     * must never make a recording publicly reachable, because SIRI —
     * not Drive — is the authorization layer.
     */
    public function test_the_adapter_never_grants_public_or_link_based_access(): void
    {
        $source = php_strip_whitespace(app_path('Booking/Storage/GoogleDriveRecordingStorage.php'))
            .php_strip_whitespace(app_path('Booking/Gateways/GoogleDriveSdkClient.php'));

        foreach (['anyoneWithLink', 'permissions', 'webContentLink', 'webViewLink', 'createPermission'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    /**
     * Exactly two scopes, each doing a job the other cannot:
     *
     *  drive.file          write/manage the files THIS APP creates —
     *                      the whole SIRI recording area.
     *  drive.meet.readonly READ the recordings GOOGLE MEET creates.
     *                      Required because a Meet recording is created
     *                      by Meet, not by this app, so drive.file
     *                      cannot see it at all.
     *
     * Asserted exactly rather than with "contains": the failure this
     * guards against is someone reaching for drive.readonly or drive to
     * make a permission error go away, which would expose the entire
     * Workspace account's Drive.
     */
    public function test_the_drive_integration_requests_only_the_two_minimum_scopes(): void
    {
        $scopes = app(GoogleDriveSdkClient::class)->requestedScopes();

        $this->assertSame([
            'https://www.googleapis.com/auth/drive.file',
            'https://www.googleapis.com/auth/drive.meet.readonly',
        ], $scopes);

        $this->assertNotContains('https://www.googleapis.com/auth/drive', $scopes);
        $this->assertNotContains('https://www.googleapis.com/auth/drive.readonly', $scopes);
    }
}
