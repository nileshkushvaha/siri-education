<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Contracts\ZoomMeetingClient;
use App\Booking\DTOs\MeetingUpdateContext;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Meetings\ManualMeetingProvider;
use App\Booking\Meetings\ZoomMeetingProvider;
use App\Booking\Services\MeetingProviderResolver;
use App\Booking\Services\ZoomConfigurationService;
use App\Enums\StudentStatus;
use App\Filament\Pages\Settings\MeetingSettingsPage;
use App\Http\Resources\Student\StudentBookingResource;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Models\User;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ZoomMeetingProviderTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'zoom_test_client_secret_value';

    /** @return array{id: string, join_url: ?string, start_url: ?string, password: ?string, timezone: ?string, status: ?string} */
    private function sanitizedZoomMeeting(string $id = '987654321'): array
    {
        return [
            'id' => $id,
            'join_url' => 'https://zoom.us/j/'.$id,
            'start_url' => 'https://zoom.us/s/'.$id.'?zak=host-start-token',
            'password' => 'p4ss',
            'timezone' => 'UTC',
            'status' => 'waiting',
        ];
    }

    private function configureZoom(bool $enabled = true): MeetingSettings
    {
        $settings = app(MeetingSettings::class);
        $settings->meetings_enabled = true;
        $settings->manual_provider_enabled = true;
        $settings->default_provider = 'manual';
        $settings->create_after_demo_booking_confirmation = true;
        $settings->create_after_paid_booking_confirmation = true;
        $settings->zoom_enabled = $enabled;
        $settings->zoom_account_id = 'acct_123';
        $settings->zoom_client_id = 'client_abc';
        $settings->zoom_client_secret = Crypt::encryptString(self::SECRET);
        $settings->zoom_host_user_id = 'host-user-1';
        $settings->save();

        return $settings;
    }

    private function bindFakeClient(): ZoomMeetingClient&Mockery\MockInterface
    {
        $client = Mockery::mock(ZoomMeetingClient::class);
        $this->app->instance(ZoomMeetingClient::class, $client);

        return $client;
    }

    private function eligibleBooking(): Booking
    {
        return Booking::factory()->confirmed()->paid()->create();
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    // ── Settings / configuration ─────────────────────────────────────

    public function test_zoom_provider_is_not_configured_when_disabled(): void
    {
        $this->configureZoom(enabled: false);

        $this->assertFalse(app(ZoomMeetingProvider::class)->isConfigured());
    }

    public function test_zoom_provider_is_not_configured_with_missing_account_id(): void
    {
        $settings = $this->configureZoom();
        $settings->zoom_account_id = null;
        $settings->save();

        $this->assertFalse(app(ZoomMeetingProvider::class)->isConfigured());
    }

    public function test_zoom_provider_is_not_configured_with_missing_client_id(): void
    {
        $settings = $this->configureZoom();
        $settings->zoom_client_id = null;
        $settings->save();

        $this->assertFalse(app(ZoomMeetingProvider::class)->isConfigured());
    }

    public function test_zoom_provider_is_not_configured_with_missing_or_undecryptable_secret(): void
    {
        $settings = $this->configureZoom();
        $settings->zoom_client_secret = null;
        $settings->save();
        $this->assertFalse(app(ZoomMeetingProvider::class)->isConfigured());

        $settings->zoom_client_secret = 'not-an-encrypted-payload';
        $settings->save();
        $this->assertFalse(app(ZoomMeetingProvider::class)->isConfigured());
    }

    public function test_zoom_provider_is_not_configured_with_missing_host_user_and_email(): void
    {
        $settings = $this->configureZoom();
        $settings->zoom_host_user_id = null;
        $settings->zoom_host_email = null;
        $settings->save();

        $this->assertFalse(app(ZoomMeetingProvider::class)->isConfigured());
    }

    public function test_resolver_blocks_zoom_when_unconfigured(): void
    {
        $this->configureZoom(enabled: false);

        $this->expectException(BookingException::class);
        app(MeetingProviderResolver::class)->resolve(ZoomMeetingProvider::KEY);
    }

    public function test_config_validation_marks_ready_only_through_token_and_live_meeting_verification(): void
    {
        $this->configureZoom();
        $client = $this->bindFakeClient();

        // Structurally-plausible credentials alone are not "ready" —
        // the mocked token validation decides, and a token failure never
        // proceeds to meeting creation.
        $client->shouldReceive('validateCredentials')->once()->andReturn(false);
        $client->shouldNotReceive('createMeeting');
        $service = app(ZoomConfigurationService::class);
        $this->assertSame('invalid', $service->check());
        $this->assertFalse($service->lastDiagnostics()->tokenAcquired);

        // A mintable token alone is not "ready" either — a real temporary
        // meeting must be created under the configured host user and is
        // always deleted afterwards.
        $client->shouldReceive('validateCredentials')->once()->andReturn(true);
        $client->shouldReceive('createMeeting')
            ->once()
            ->withArgs(fn (string $hostUser): bool => $hostUser === 'host-user-1')
            ->andReturn($this->sanitizedZoomMeeting('777'));
        $client->shouldReceive('deleteMeeting')->once()->with('777')->andReturn(true);

        $service = app(ZoomConfigurationService::class);
        $this->assertSame('ready', $service->check());
        $this->assertNull($service->lastDiagnostic());

        $diagnostics = $service->lastDiagnostics();
        $this->assertSame('acct_123', $diagnostics->accountId);
        $this->assertSame('client_abc', $diagnostics->clientId);
        $this->assertSame('host-user-1', $diagnostics->hostUser);
        $this->assertTrue($diagnostics->tokenAcquired);
        $this->assertTrue($diagnostics->meetingCreationVerified);
    }

    public function test_config_validation_marks_invalid_when_test_meeting_creation_fails_and_never_leaks_secrets(): void
    {
        $this->configureZoom();
        $client = $this->bindFakeClient();

        $client->shouldReceive('validateCredentials')->once()->andReturn(true);
        $client->shouldReceive('createMeeting')
            ->once()
            ->andThrow(new GatewayRequestException('Zoom API failed to create meeting (HTTP 404): User does not exist: host-user-1.'));
        $client->shouldNotReceive('deleteMeeting');

        $service = app(ZoomConfigurationService::class);

        $this->assertSame('invalid', $service->check());
        $this->assertStringContainsString('Test meeting creation failed', (string) $service->lastDiagnostic());
        $this->assertStringNotContainsString(self::SECRET, (string) $service->lastDiagnostic());
        $this->assertFalse($service->lastDiagnostics()->meetingCreationVerified);
    }

    public function test_config_validation_deletes_the_temporary_meeting_even_when_it_has_no_join_url(): void
    {
        $this->configureZoom();
        $client = $this->bindFakeClient();

        $meeting = $this->sanitizedZoomMeeting('888');
        $meeting['join_url'] = null;

        $client->shouldReceive('validateCredentials')->once()->andReturn(true);
        $client->shouldReceive('createMeeting')->once()->andReturn($meeting);
        $client->shouldReceive('deleteMeeting')->once()->with('888')->andReturn(true);

        $service = app(ZoomConfigurationService::class);

        $this->assertSame('invalid', $service->check());
        $this->assertStringContainsString('no join URL', (string) $service->lastDiagnostic());
    }

    public function test_config_validation_still_reports_invalid_when_temporary_meeting_cleanup_fails(): void
    {
        $this->configureZoom();
        $client = $this->bindFakeClient();

        $meeting = $this->sanitizedZoomMeeting('999');
        $meeting['join_url'] = null;

        $client->shouldReceive('validateCredentials')->once()->andReturn(true);
        $client->shouldReceive('createMeeting')->once()->andReturn($meeting);
        // Cleanup failure must never mask the already-diagnosed outcome.
        $client->shouldReceive('deleteMeeting')->once()->andThrow(new GatewayRequestException('Zoom API failed to delete meeting (HTTP 500).'));

        $this->assertSame('invalid', app(ZoomConfigurationService::class)->check());
    }

    public function test_config_validation_fails_closed_before_ever_calling_the_client(): void
    {
        $settings = $this->configureZoom();
        $client = $this->bindFakeClient();
        $client->shouldNotReceive('validateCredentials');

        $settings->zoom_enabled = false;
        $settings->save();
        $this->assertSame('not_configured', app(ZoomConfigurationService::class)->check());

        $settings->zoom_enabled = true;
        $settings->zoom_account_id = null;
        $settings->save();
        $this->assertSame('incomplete', app(ZoomConfigurationService::class)->check());

        $settings->zoom_account_id = 'acct_123';
        $settings->zoom_client_secret = 'not-an-encrypted-payload';
        $settings->save();
        $this->assertSame('invalid', app(ZoomConfigurationService::class)->check());

        $settings->zoom_client_secret = Crypt::encryptString(self::SECRET);
        $settings->zoom_host_user_id = null;
        $settings->zoom_host_email = null;
        $settings->save();
        $this->assertSame('incomplete', app(ZoomConfigurationService::class)->check());
    }

    public function test_zoom_secret_is_encrypted_on_save_and_never_rendered_back(): void
    {
        $this->configureZoom();
        $this->actingAs($this->superAdmin());

        $component = Livewire::test(MeetingSettingsPage::class);

        // Never re-displayed: the form field starts blank despite a stored secret.
        $this->assertNull($component->get('data.zoom_client_secret'));

        $component->set('data.zoom_client_secret', 'brand-new-secret')->call('save');

        $stored = app()->make(MeetingSettings::class)->refresh()->zoom_client_secret;
        $this->assertNotSame('brand-new-secret', $stored);
        $this->assertSame('brand-new-secret', Crypt::decryptString($stored));
    }

    public function test_blank_zoom_secret_input_preserves_existing_secret(): void
    {
        $settings = $this->configureZoom();
        $original = $settings->zoom_client_secret;
        $this->actingAs($this->superAdmin());

        Livewire::test(MeetingSettingsPage::class)
            ->set('data.zoom_account_id', 'acct_456')
            ->call('save');

        $fresh = app()->make(MeetingSettings::class)->refresh();
        $this->assertSame('acct_456', $fresh->zoom_account_id);
        $this->assertSame($original, $fresh->zoom_client_secret);
        $this->assertSame(self::SECRET, Crypt::decryptString($fresh->zoom_client_secret));
    }

    // ── Provider through BookingMeetingService ───────────────────────

    public function test_eligible_booking_creates_zoom_meeting_with_id_join_url_and_hidden_password(): void
    {
        $this->configureZoom();
        $client = $this->bindFakeClient();
        $booking = $this->eligibleBooking();

        $client->shouldReceive('createMeeting')
            ->once()
            ->withArgs(function (string $hostUser, array $payload) use ($booking): bool {
                $this->assertSame('host-user-1', $hostUser);
                $this->assertSame(2, $payload['type']); // scheduled, never instant
                $this->assertFalse($payload['settings']['join_before_host']);
                $this->assertSame('none', $payload['settings']['auto_recording']);
                // Agenda/topic carry no payment details.
                $this->assertStringNotContainsString((string) $booking->price, $payload['agenda']);

                return true;
            })
            ->andReturn($this->sanitizedZoomMeeting());

        $meeting = app(BookingMeetingServiceInterface::class)->createMeeting($booking, ZoomMeetingProvider::KEY);

        $this->assertSame(MeetingStatus::Created, $meeting->status);
        $this->assertSame(ZoomMeetingProvider::KEY, $meeting->provider);
        $this->assertSame('987654321', $meeting->provider_meeting_id);
        $this->assertSame('https://zoom.us/j/987654321', $meeting->join_url);

        // start_url/password persist for admin/host use but never serialize.
        $this->assertSame('https://zoom.us/s/987654321?zak=host-start-token', $meeting->host_url);
        $this->assertSame('p4ss', $meeting->password);
        $serialized = $meeting->toArray();
        $this->assertArrayNotHasKey('host_url', $serialized);
        $this->assertArrayNotHasKey('password', $serialized);
    }

    public function test_zoom_meeting_metadata_never_contains_raw_response_or_secrets(): void
    {
        $this->configureZoom();
        $client = $this->bindFakeClient();
        $client->shouldReceive('createMeeting')->once()->andReturn($this->sanitizedZoomMeeting());

        $meeting = app(BookingMeetingServiceInterface::class)
            ->createMeeting($this->eligibleBooking(), ZoomMeetingProvider::KEY);

        $this->assertSame(['zoom_status' => 'waiting'], $meeting->metadata);
        $metadataJson = json_encode($meeting->metadata);
        $this->assertStringNotContainsString(self::SECRET, $metadataJson);
        $this->assertStringNotContainsString('zak=', $metadataJson);
    }

    public function test_zoom_failure_records_failed_status_and_booking_stays_confirmed_and_paid(): void
    {
        $this->configureZoom();
        $client = $this->bindFakeClient();
        $booking = $this->eligibleBooking();

        $client->shouldReceive('createMeeting')
            ->once()
            ->andThrow(new GatewayRequestException('Zoom API failed to create meeting (HTTP 429): rate limited token_AAAAABBBBBCCCCCDDDDD12345'));

        $meeting = app(BookingMeetingServiceInterface::class)->createMeeting($booking, ZoomMeetingProvider::KEY);

        $this->assertSame(MeetingStatus::Failed, $meeting->status);
        $this->assertNotNull($meeting->failure_reason);
        $this->assertStringNotContainsString('token_AAAAABBBBBCCCCCDDDDD12345', $meeting->failure_reason);

        $booking->refresh();
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame('paid', $booking->payment_status->value);
    }

    public function test_admin_can_fallback_to_manual_after_zoom_failure(): void
    {
        $this->configureZoom();
        $client = $this->bindFakeClient();
        $booking = $this->eligibleBooking();

        $client->shouldReceive('createMeeting')->once()->andThrow(new GatewayRequestException('Zoom is down.'));

        $service = app(BookingMeetingServiceInterface::class);
        $failed = $service->createMeeting($booking, ZoomMeetingProvider::KEY);
        $this->assertSame(MeetingStatus::Failed, $failed->status);

        $fallback = $service->saveManualMeeting($booking, new MeetingUpdateContext(joinUrl: 'https://meet.example.test/fallback'));

        $this->assertSame(MeetingStatus::Created, $fallback->status);
        $this->assertSame(ManualMeetingProvider::KEY, $fallback->provider);
        $this->assertSame(1, BookingMeeting::query()->where('booking_id', $booking->id)->count());
    }

    public function test_zoom_retry_updates_existing_zoom_meeting_through_client(): void
    {
        $this->configureZoom();
        $client = $this->bindFakeClient();
        $booking = $this->eligibleBooking();

        BookingMeeting::factory()->zoom()->failed()->create([
            'booking_id' => $booking->id,
            'provider_meeting_id' => '987654321',
        ]);

        $client->shouldReceive('updateMeeting')
            ->once()
            ->withArgs(fn (string $meetingId): bool => $meetingId === '987654321')
            ->andReturn($this->sanitizedZoomMeeting());
        $client->shouldNotReceive('createMeeting');

        $meeting = app(BookingMeetingServiceInterface::class)->createMeeting($booking, ZoomMeetingProvider::KEY);

        $this->assertSame(MeetingStatus::Created, $meeting->status);
    }

    public function test_cross_provider_retry_creates_fresh_zoom_meeting_instead_of_updating_foreign_id(): void
    {
        $this->configureZoom();
        $client = $this->bindFakeClient();
        $booking = $this->eligibleBooking();

        // A failed Google row must never be PATCHed against the Zoom API.
        BookingMeeting::factory()->google()->failed()->create([
            'booking_id' => $booking->id,
            'provider_event_id' => 'google_evt_123',
        ]);

        $client->shouldReceive('createMeeting')->once()->andReturn($this->sanitizedZoomMeeting());
        $client->shouldNotReceive('updateMeeting');

        $meeting = app(BookingMeetingServiceInterface::class)->createMeeting($booking, ZoomMeetingProvider::KEY);

        $this->assertSame(ZoomMeetingProvider::KEY, $meeting->provider);
        $this->assertNull($meeting->provider_event_id); // stale Google id cleared
        $this->assertSame(1, BookingMeeting::query()->where('booking_id', $booking->id)->count());
    }

    public function test_zoom_cancel_deletes_meeting_through_client(): void
    {
        $this->configureZoom();
        $client = $this->bindFakeClient();
        $booking = $this->eligibleBooking();

        BookingMeeting::factory()->zoom()->created('https://zoom.us/j/987654321')->create([
            'booking_id' => $booking->id,
            'provider_meeting_id' => '987654321',
        ]);

        $client->shouldReceive('deleteMeeting')
            ->once()
            ->withArgs(fn (string $meetingId): bool => $meetingId === '987654321')
            ->andReturn(true);

        $meeting = app(BookingMeetingServiceInterface::class)->cancelMeeting($booking);

        $this->assertSame(MeetingStatus::Cancelled, $meeting->status);
    }

    public function test_idempotent_create_returns_existing_zoom_meeting_without_client_call(): void
    {
        $this->configureZoom();
        $client = $this->bindFakeClient();
        $booking = $this->eligibleBooking();

        BookingMeeting::factory()->zoom()->created('https://zoom.us/j/987654321')->create([
            'booking_id' => $booking->id,
            'provider_meeting_id' => '987654321',
        ]);

        $client->shouldNotReceive('createMeeting');
        $client->shouldNotReceive('updateMeeting');

        $meeting = app(BookingMeetingServiceInterface::class)->createMeeting($booking, ZoomMeetingProvider::KEY);

        $this->assertSame('987654321', $meeting->provider_meeting_id);
        $this->assertSame(1, BookingMeeting::query()->where('booking_id', $booking->id)->count());
    }

    public function test_ineligible_bookings_never_reach_the_zoom_client(): void
    {
        $this->configureZoom();
        $client = $this->bindFakeClient();
        $client->shouldNotReceive('createMeeting');

        $service = app(BookingMeetingServiceInterface::class);

        $pending = Booking::factory()->create(['status' => BookingStatus::Pending]);
        $this->assertNull($service->createMeeting($pending, ZoomMeetingProvider::KEY));

        $cancelled = Booking::factory()->cancelled()->create();
        $this->assertNull($service->createMeeting($cancelled, ZoomMeetingProvider::KEY));

        $unpaid = Booking::factory()->confirmed()->create(['payment_status' => 'pending']);
        $this->assertNull($service->createMeeting($unpaid, ZoomMeetingProvider::KEY));
    }

    // ── Visibility ───────────────────────────────────────────────────

    public function test_student_sees_zoom_join_url_but_never_start_url_or_metadata(): void
    {
        $this->configureZoom();
        $settings = app(MeetingSettings::class);
        $settings->student_join_url_visible = true;
        $settings->save();

        $booking = $this->eligibleBooking();
        BookingMeeting::factory()->zoom()->created('https://zoom.us/j/987654321')->create([
            'booking_id' => $booking->id,
            'provider_meeting_id' => '987654321',
            'host_url' => 'https://zoom.us/s/987654321?zak=host-start-token',
            'password' => 'p4ss',
            // Phase 24H.2B: the resource releases the URL only inside the visibility window.
            'starts_at' => now()->addMinutes(10),
            'ends_at' => now()->addMinutes(40),
            'metadata' => ['zoom_status' => 'waiting'],
        ]);

        // Phase 24H.2A: the resource releases the URL only to the
        // booking's own Active student as the request viewer.
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $viewer = $booking->student;
        $viewer->assignRole('student');
        $viewer->profile()->update(['student_status' => StudentStatus::Active]);
        $viewerRequest = Request::create('/api/test');
        $viewerRequest->setUserResolver(fn () => $viewer);

        $resource = (new StudentBookingResource($booking->fresh()->load('meeting')))->toArray($viewerRequest);
        $resource = collect($resource)->reject(fn ($v) => $v instanceof MissingValue)->all();

        $this->assertSame('https://zoom.us/j/987654321', $resource['meeting_url']);
        $this->assertSame('p4ss', $resource['meeting_password']);
        $flattened = json_encode($resource);
        $this->assertStringNotContainsString('zak=', $flattened);
        $this->assertStringNotContainsString('start_url', $flattened);
        $this->assertStringNotContainsString('zoom_status', $flattened);
    }

    // ── Boundaries ───────────────────────────────────────────────────

    public function test_no_zoom_webhook_route_exists(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('zoom', $route->uri());
        }
    }
}
