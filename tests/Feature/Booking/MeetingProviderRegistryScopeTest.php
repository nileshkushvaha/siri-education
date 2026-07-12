<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\GoogleCalendarClient;
use App\Booking\Meetings\GoogleCalendarMeetProvider;
use App\Booking\Registry\MeetingProviderRegistry;
use App\Booking\Services\MeetingProviderResolver;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Tests\TestCase;

/**
 * MeetingProviderRegistry/MeetingProviderResolver must be bound
 * scoped(), not singleton() — a plain singleton would build its
 * registered GoogleCalendarMeetProvider (and the MeetingSettings
 * snapshot injected into it) exactly once per worker process. On a
 * long-running queue worker (`queue:work` without `--once`, Horizon),
 * every job after the first would then keep using whatever Google
 * credentials/platform_meeting_account were current when the worker
 * happened to build its first meeting-related job — silently ignoring
 * any admin credential update until the worker restarts.
 *
 * Laravel resets scoped() container bindings after every queue job
 * (Illuminate\Queue\QueueServiceProvider calls
 * $app->forgetScopedInstances()); this test simulates that boundary
 * directly to prove the fix without spinning up a real queue worker.
 */
class MeetingProviderRegistryScopeTest extends TestCase
{
    use RefreshDatabase;

    private function configureGoogle(string $platformMeetingAccount): void
    {
        $settings = app(MeetingSettings::class);
        $settings->meetings_enabled = true;
        $settings->google_meet_enabled = true;
        $settings->google_auth_type = 'service_account';
        $settings->google_calendar_id = 'primary';
        $settings->platform_meeting_account = $platformMeetingAccount;
        $settings->google_credentials_json = Crypt::encryptString(
            json_encode(['type' => 'service_account', 'client_id' => '116902683368346528512', 'client_email' => 'siri-education@siri-education.iam.gserviceaccount.com', 'private_key' => 'FAKE_PRIVATE_KEY_TOKEN_ABCDEFGHIJKLMNOP']),
        );
        $settings->save();
    }

    public function test_registry_resolves_fresh_provider_state_across_a_simulated_queue_job_boundary(): void
    {
        $this->app->instance(GoogleCalendarClient::class, Mockery::mock(GoogleCalendarClient::class));

        $this->configureGoogle('meetings@sirieducation.com');

        $providerBeforeUpdate = app(MeetingProviderRegistry::class)->get(GoogleCalendarMeetProvider::KEY);
        $this->assertTrue($providerBeforeUpdate->isConfigured());

        // An admin disables the platform account mid-worker-life (e.g.
        // pending a credential rotation) — this must never be masked by
        // a stale, worker-lifetime-cached provider.
        $settings = app(MeetingSettings::class);
        $settings->platform_meeting_account = null;
        $settings->save();

        // The exact reset Laravel's queue worker performs between jobs.
        $this->app->forgetScopedInstances();

        $providerAfterUpdate = app(MeetingProviderRegistry::class)->get(GoogleCalendarMeetProvider::KEY);

        $this->assertNotSame($providerBeforeUpdate, $providerAfterUpdate);
        $this->assertFalse($providerAfterUpdate->isConfigured());
    }

    public function test_resolver_rejects_google_meet_after_credentials_are_cleared_across_a_simulated_queue_job_boundary(): void
    {
        $this->app->instance(GoogleCalendarClient::class, Mockery::mock(GoogleCalendarClient::class));

        $this->configureGoogle('meetings@sirieducation.com');

        // Resolving once (as the first job on a worker would) must not
        // pin the resolver/registry to this moment's settings.
        app(MeetingProviderResolver::class)->resolve(GoogleCalendarMeetProvider::KEY);

        $settings = app(MeetingSettings::class);
        $settings->google_meet_enabled = false;
        $settings->save();

        $this->app->forgetScopedInstances();

        $this->expectExceptionMessageMatches('/not enabled or its credentials are missing\/invalid/');
        app(MeetingProviderResolver::class)->resolve(GoogleCalendarMeetProvider::KEY);
    }
}
