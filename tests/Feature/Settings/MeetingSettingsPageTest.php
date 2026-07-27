<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Booking\Contracts\ZoomMeetingClient;
use App\Filament\Pages\Settings\MeetingSettingsPage;
use App\Filament\Pages\Settings\PlatformFoundationSettingsPage;
use App\Models\Activity;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MeetingSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_meeting_settings_page_renders_for_super_admin(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/admin/settings/meetings')
            ->assertOk()
            ->assertSee('Meeting Settings');
    }

    public function test_meeting_settings_page_denies_users_without_settings_access(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->actingAs($user)->get('/admin/settings/meetings')->assertForbidden();
    }

    public function test_meeting_settings_page_saves_meeting_fields(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(MeetingSettingsPage::class)
            ->set('data.meetings_enabled', true)
            ->set('data.default_provider', 'google_meet')
            ->set('data.meeting_recording_enabled', true)
            ->set('data.create_after_paid_booking_confirmation', false)
            ->set('data.student_join_url_visible', false)
            ->call('save')
            ->assertNotified('Meeting settings saved');

        $settings = app()->make(MeetingSettings::class)->refresh();
        $this->assertTrue($settings->meetings_enabled);
        $this->assertSame('google_meet', $settings->default_provider);
        $this->assertTrue($settings->recording_enabled);
        $this->assertFalse($settings->create_after_paid_booking_confirmation);
        $this->assertFalse($settings->student_join_url_visible);
    }

    private const FIRST_CREDENTIALS_JSON = '{"type":"service_account","client_id":"111111111111111111111","client_email":"first@siri-education.iam.gserviceaccount.com","private_key":"FAKE_PRIVATE_KEY_TOKEN_ABCDEFGHIJKLMNOP"}';

    private const REPLACEMENT_CREDENTIALS_JSON = '{"type":"service_account","client_id":"116902683368346528512","client_email":"siri-education@siri-education.iam.gserviceaccount.com","private_key":"FAKE_PRIVATE_KEY_TOKEN_QRSTUVWXYZ0123456789"}';

    public function test_saving_with_blank_google_credentials_json_keeps_the_existing_encrypted_value(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(MeetingSettingsPage::class)
            ->set('data.default_provider', 'google_meet')
            ->set('data.google_meet_enabled', true)
            ->set('data.google_auth_type', 'service_account')
            ->set('data.google_calendar_id', 'primary')
            ->set('data.google_credentials_json', self::FIRST_CREDENTIALS_JSON)
            ->call('save');

        $original = app(MeetingSettings::class)->refresh()->google_credentials_json;
        $this->assertNotNull($original);
        $originalUpdatedAt = app(MeetingSettings::class)->google_credentials_updated_at;
        $this->assertNotNull($originalUpdatedAt);

        // Re-saving with the (deliberately never re-displayed) JSON
        // field left blank must never clear/overwrite the stored
        // credential — nor its "last replaced" timestamp.
        Livewire::test(MeetingSettingsPage::class)
            ->set('data.default_provider', 'google_meet')
            ->set('data.google_meet_enabled', true)
            ->set('data.google_auth_type', 'service_account')
            ->set('data.google_calendar_id', 'primary')
            ->set('data.google_credentials_json', null)
            ->call('save');

        $settings = app(MeetingSettings::class)->refresh();
        $this->assertSame($original, $settings->google_credentials_json);
        $this->assertSame($originalUpdatedAt, $settings->google_credentials_updated_at);
    }

    public function test_saving_a_new_google_credentials_json_replaces_the_prior_stored_credential(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(MeetingSettingsPage::class)
            ->set('data.default_provider', 'google_meet')
            ->set('data.google_meet_enabled', true)
            ->set('data.google_auth_type', 'service_account')
            ->set('data.google_calendar_id', 'primary')
            ->set('data.google_credentials_json', self::FIRST_CREDENTIALS_JSON)
            ->call('save');

        $first = app(MeetingSettings::class)->refresh()->google_credentials_json;

        // A compromised-key rotation: a genuinely new JSON must replace
        // (not merge with, not ignore) the previously stored credential.
        Livewire::test(MeetingSettingsPage::class)
            ->set('data.default_provider', 'google_meet')
            ->set('data.google_meet_enabled', true)
            ->set('data.google_auth_type', 'service_account')
            ->set('data.google_calendar_id', 'primary')
            ->set('data.google_credentials_json', self::REPLACEMENT_CREDENTIALS_JSON)
            ->call('save')
            ->assertNotified('Meeting settings saved');

        $settings = app(MeetingSettings::class)->refresh();
        $this->assertNotSame($first, $settings->google_credentials_json);
        $this->assertNotNull($settings->google_credentials_updated_at);
        $this->assertSame(
            self::REPLACEMENT_CREDENTIALS_JSON,
            Crypt::decryptString($settings->google_credentials_json),
        );
    }

    public function test_saving_malformed_google_credentials_json_is_rejected_and_leaves_existing_credential_untouched(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(MeetingSettingsPage::class)
            ->set('data.default_provider', 'google_meet')
            ->set('data.google_meet_enabled', true)
            ->set('data.google_auth_type', 'service_account')
            ->set('data.google_calendar_id', 'primary')
            ->set('data.google_credentials_json', self::FIRST_CREDENTIALS_JSON)
            ->call('save');

        $original = app(MeetingSettings::class)->refresh()->google_credentials_json;

        $this->assertFalse($original === null);
        $this->assertFalse(app(MeetingSettings::class)->meetings_enabled);

        // Missing client_id — must be rejected outright, not silently
        // accepted or silently ignored. Also flips meetings_enabled to
        // true in the same submit, to prove the whole save aborts
        // atomically rather than partially applying the rest of the form.
        Livewire::test(MeetingSettingsPage::class)
            ->set('data.default_provider', 'google_meet')
            ->set('data.meetings_enabled', true)
            ->set('data.google_meet_enabled', true)
            ->set('data.google_auth_type', 'service_account')
            ->set('data.google_calendar_id', 'primary')
            ->set('data.google_credentials_json', '{"type":"service_account","client_email":"x@y.iam.gserviceaccount.com","private_key":"KEY"}')
            ->call('save')
            ->assertNotified('Google credentials not saved');

        $settings = app(MeetingSettings::class)->refresh();
        $this->assertSame($original, $settings->google_credentials_json);
        $this->assertFalse($settings->meetings_enabled);
    }

    public function test_platform_foundation_page_no_longer_exposes_meeting_fields(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(PlatformFoundationSettingsPage::class)
            ->assertFormFieldDoesNotExist('default_provider')
            ->assertFormFieldDoesNotExist('zoom_client_secret')
            ->assertFormFieldDoesNotExist('google_credentials_json');
    }

    public function test_validate_zoom_configuration_action_reports_status(): void
    {
        // Unconfigured: fails closed without ever touching the client.
        $client = Mockery::mock(ZoomMeetingClient::class);
        $client->shouldNotReceive('validateCredentials');
        $this->app->instance(ZoomMeetingClient::class, $client);

        $this->actingAs($this->superAdmin());

        Livewire::test(MeetingSettingsPage::class)
            ->call('validateZoomConfiguration')
            ->assertNotified('Zoom configuration: not_configured');
    }

    public function test_test_google_configuration_action_reports_status(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(MeetingSettingsPage::class)
            ->call('testGoogleConfiguration')
            ->assertNotified('Google configuration: not_configured');
    }

    // ── Audit coverage, atomicity, and credential redaction ──────

    private function latestMeetingSettingsUpdate(): ?Activity
    {
        return Activity::query()
            ->where('event', 'settings_updated')
            ->where('properties->settings_class', MeetingSettings::class)
            ->latest('id')
            ->first();
    }

    public function test_google_credentials_json_is_never_stored_in_audit_metadata(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(MeetingSettingsPage::class)
            ->set('data.default_provider', 'google_meet')
            ->set('data.google_meet_enabled', true)
            ->set('data.google_auth_type', 'service_account')
            ->set('data.google_calendar_id', 'primary')
            ->set('data.google_credentials_json', self::FIRST_CREDENTIALS_JSON)
            ->call('save');

        $activity = $this->latestMeetingSettingsUpdate();
        $this->assertNotNull($activity);

        $changed = $activity->properties['changed']['google_credentials_json'];
        $this->assertEqualsCanonicalizing(['changed', 'previously_set', 'now_set', 'action'], array_keys($changed));
        $this->assertSame('set', $changed['action']);

        $serialized = (string) json_encode($activity->properties);
        $this->assertStringNotContainsString('FAKE_PRIVATE_KEY_TOKEN', $serialized);
        $this->assertStringNotContainsString('client_email', $serialized);
        $this->assertStringNotContainsString(self::FIRST_CREDENTIALS_JSON, $serialized);
    }

    public function test_zoom_client_secret_is_never_stored_in_audit_metadata(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(MeetingSettingsPage::class)
            ->set('data.zoom_enabled', true)
            ->set('data.zoom_client_id', 'synthetic-zoom-client-id')
            ->set('data.zoom_client_secret', 'synthetic-zoom-client-secret')
            ->call('save');

        $activity = $this->latestMeetingSettingsUpdate();
        $this->assertNotNull($activity);

        $changed = $activity->properties['changed']['zoom_client_secret'];
        $this->assertSame('set', $changed['action']);

        // The public client_id is a plain, visible before/after value.
        $this->assertSame('synthetic-zoom-client-id', $activity->properties['changed']['zoom_client_id']['to']);

        $serialized = (string) json_encode($activity->properties);
        $this->assertStringNotContainsString('synthetic-zoom-client-secret', $serialized);
    }

    public function test_saving_meeting_settings_with_no_changes_creates_no_audit_event(): void
    {
        $this->actingAs($this->superAdmin());

        $settings = app(MeetingSettings::class);

        Livewire::test(MeetingSettingsPage::class)
            ->set('data.meetings_enabled', $settings->meetings_enabled)
            ->set('data.default_provider', $settings->default_provider)
            ->call('save');

        $this->assertNull($this->latestMeetingSettingsUpdate());
    }

    public function test_a_forced_audit_failure_rolls_back_the_meeting_settings_change(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->meetings_enabled = false;
        $settings->save();

        $this->actingAs($this->superAdmin());

        $this->app->instance(AuditTrailService::class, new class
        {
            public function logUser(...$args): never
            {
                throw new RuntimeException('Simulated audit failure');
            }
        });

        Livewire::test(MeetingSettingsPage::class)
            ->set('data.meetings_enabled', true)
            ->set('data.default_provider', 'google_meet')
            ->call('save');

        // The settings write must not have committed either — proves the
        // save() and its audit record share one transaction, not two
        // separate steps (the pre-24S implementation wrote the audit
        // event AFTER its own manual DB::transaction had already
        // committed the settings change).
        $this->assertFalse(app(MeetingSettings::class)->meetings_enabled);
        $this->assertNull($this->latestMeetingSettingsUpdate());
    }
}
