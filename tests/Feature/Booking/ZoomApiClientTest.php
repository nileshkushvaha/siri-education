<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\ZoomMeetingClient;
use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Gateways\ZoomApiClient;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ZoomApiClientTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'zoom_test_client_secret_value';

    private const TOKEN = 'zoom_access_token_AAAABBBBCCCCDDDD';

    protected function setUp(): void
    {
        parent::setUp();

        $settings = app(MeetingSettings::class);
        $settings->zoom_enabled = true;
        $settings->zoom_account_id = 'acct_123';
        $settings->zoom_client_id = 'client_abc';
        $settings->zoom_client_secret = Crypt::encryptString(self::SECRET);
        $settings->zoom_host_user_id = 'host-user-1';
        $settings->save();
    }

    private function client(): ZoomApiClient
    {
        return app(ZoomApiClient::class);
    }

    /** @param  array<string, mixed>  $meeting */
    private function fakeZoom(array $meeting = [], int $meetingStatus = 201): void
    {
        Http::fake([
            'zoom.us/oauth/token*' => Http::response(['access_token' => self::TOKEN, 'expires_in' => 3600]),
            'api.zoom.us/v2/users/*/meetings' => Http::response($meeting !== [] ? $meeting : [
                'id' => 987654321,
                'join_url' => 'https://zoom.us/j/987654321',
                'start_url' => 'https://zoom.us/s/987654321?zak=host-token',
                'password' => 'p4ss',
                'timezone' => 'Asia/Kolkata',
                'status' => 'waiting',
                'host_id' => 'raw-host-id-should-never-leak',
                'settings' => ['alternative_hosts' => 'internal@example.com'],
            ], $meetingStatus),
        ]);
    }

    public function test_token_is_requested_with_basic_auth_and_account_credentials_grant(): void
    {
        $this->fakeZoom();

        $this->client()->createMeeting('host-user-1', ['topic' => 'Lesson']);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'zoom.us/oauth/token')) {
                return false;
            }

            $expected = 'Basic '.base64_encode('client_abc:'.self::SECRET);

            return $request->hasHeader('Authorization', $expected)
                && str_contains($request->body(), 'grant_type=account_credentials')
                && str_contains($request->body(), 'account_id=acct_123');
        });

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v2/users/host-user-1/meetings')
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));
    }

    public function test_token_is_cached_across_api_calls(): void
    {
        $this->fakeZoom();

        $this->client()->createMeeting('host-user-1', ['topic' => 'Lesson A']);
        $this->client()->createMeeting('host-user-1', ['topic' => 'Lesson B']);

        Http::assertSentCount(3); // 1 token + 2 meeting creates — never a second token mint.
    }

    public function test_create_meeting_returns_only_sanitized_whitelisted_fields(): void
    {
        $this->fakeZoom();

        $meeting = $this->client()->createMeeting('host-user-1', ['topic' => 'Lesson']);

        $this->assertSame(
            ['id', 'join_url', 'start_url', 'password', 'timezone', 'status'],
            array_keys($meeting),
        );
        $this->assertSame('987654321', $meeting['id']);
        $this->assertSame('https://zoom.us/j/987654321', $meeting['join_url']);
        // The raw response's host_id / settings never cross the boundary.
        $this->assertStringNotContainsString('raw-host-id-should-never-leak', json_encode($meeting));
    }

    public function test_api_failure_throws_safe_exception_without_secret_or_token(): void
    {
        Http::fake([
            'zoom.us/oauth/token*' => Http::response(['access_token' => self::TOKEN, 'expires_in' => 3600]),
            'api.zoom.us/v2/users/*/meetings' => Http::response(['code' => 1001, 'message' => 'User does not exist.'], 404),
        ]);

        try {
            $this->client()->createMeeting('missing-user', ['topic' => 'Lesson']);
            $this->fail('Expected a GatewayRequestException.');
        } catch (GatewayRequestException $e) {
            $this->assertStringContainsString('HTTP 404', $e->getMessage());
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function test_token_mint_failure_throws_safe_exception(): void
    {
        Http::fake([
            'zoom.us/oauth/token*' => Http::response(['reason' => 'Invalid client', 'error' => 'invalid_client'], 401),
        ]);

        try {
            $this->client()->createMeeting('host-user-1', ['topic' => 'Lesson']);
            $this->fail('Expected a GatewayRequestException.');
        } catch (GatewayRequestException $e) {
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
    }

    public function test_client_never_logs_token_or_secret(): void
    {
        Log::spy();
        $this->fakeZoom();

        $this->client()->createMeeting('host-user-1', ['topic' => 'Lesson']);

        foreach (['debug', 'info', 'warning', 'error'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    public function test_update_meeting_patches_then_returns_fresh_sanitized_state(): void
    {
        Http::fake([
            'zoom.us/oauth/token*' => Http::response(['access_token' => self::TOKEN, 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/987654321' => Http::sequence()
                ->push(null, 204) // PATCH
                ->push([          // follow-up GET
                    'id' => 987654321,
                    'join_url' => 'https://zoom.us/j/987654321',
                    'start_url' => 'https://zoom.us/s/987654321',
                    'password' => 'p4ss',
                    'timezone' => 'UTC',
                    'status' => 'waiting',
                ]),
        ]);

        $meeting = $this->client()->updateMeeting('987654321', ['topic' => 'Lesson (rescheduled)']);

        $this->assertSame('987654321', $meeting['id']);
        $this->assertSame('https://zoom.us/j/987654321', $meeting['join_url']);
    }

    public function test_delete_meeting_treats_404_as_success(): void
    {
        Http::fake([
            'zoom.us/oauth/token*' => Http::response(['access_token' => self::TOKEN, 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/*' => Http::response(['message' => 'Meeting not found.'], 404),
        ]);

        $this->assertTrue($this->client()->deleteMeeting('gone-already'));
    }

    public function test_validate_credentials_true_on_token_success(): void
    {
        Http::fake(['zoom.us/oauth/token*' => Http::response(['access_token' => self::TOKEN, 'expires_in' => 3600])]);

        $this->assertTrue($this->client()->validateCredentials());
    }

    public function test_validate_credentials_false_on_token_failure(): void
    {
        Http::fake(['zoom.us/oauth/token*' => Http::response(['error' => 'invalid_client'], 401)]);

        $this->assertFalse($this->client()->validateCredentials());
    }

    public function test_container_binds_contract_to_this_client(): void
    {
        $this->assertInstanceOf(ZoomApiClient::class, app(ZoomMeetingClient::class));
    }
}
