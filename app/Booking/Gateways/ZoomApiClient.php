<?php

declare(strict_types=1);

namespace App\Booking\Gateways;

use App\Booking\Contracts\ZoomMeetingClient;
use App\Booking\Exceptions\GatewayRequestException;
use App\Settings\MeetingSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Zoom REST API v2 over Laravel's HTTP client — the only class in this
 * codebase that ever talks HTTP to Zoom (no third-party Zoom SDK; none
 * is approved). Server-to-Server OAuth: an account-credentials token is
 * minted with Basic auth (client_id:client_secret) and cached until
 * shortly before expiry.
 *
 * Secret hygiene: the client secret is decrypted per token request and
 * never stored on this class, logged, or echoed into an exception; the
 * access token lives only in the cache entry and the Authorization
 * header. Errors surface as GatewayRequestException with a status code
 * and Zoom's short `message` only — never a request/response dump.
 */
final class ZoomApiClient implements ZoomMeetingClient
{
    private const string TOKEN_URL = 'https://zoom.us/oauth/token';

    private const string API_BASE = 'https://api.zoom.us/v2';

    /** Refresh this many seconds before Zoom's stated expiry. */
    private const int TOKEN_EXPIRY_BUFFER_SECONDS = 60;

    public function __construct(
        private readonly MeetingSettings $settings,
    ) {}

    public function createMeeting(string $hostUser, array $payload): array
    {
        $response = $this->request()->post(
            sprintf('%s/users/%s/meetings', self::API_BASE, rawurlencode($hostUser)),
            $payload,
        );

        if ($response->failed()) {
            throw new GatewayRequestException($this->safeError('create meeting', $response));
        }

        return $this->sanitizeMeeting($response->json() ?? []);
    }

    public function updateMeeting(string $meetingId, array $payload): array
    {
        $patch = $this->request()->patch(
            sprintf('%s/meetings/%s', self::API_BASE, rawurlencode($meetingId)),
            $payload,
        );

        if ($patch->failed()) {
            throw new GatewayRequestException($this->safeError('update meeting', $patch));
        }

        // PATCH answers 204 with no body — re-fetch for the current state.
        $fresh = $this->request()->get(sprintf('%s/meetings/%s', self::API_BASE, rawurlencode($meetingId)));

        if ($fresh->failed()) {
            throw new GatewayRequestException($this->safeError('fetch meeting', $fresh));
        }

        return $this->sanitizeMeeting($fresh->json() ?? []);
    }

    public function deleteMeeting(string $meetingId): bool
    {
        $response = $this->request()->delete(sprintf('%s/meetings/%s', self::API_BASE, rawurlencode($meetingId)));

        // 404 = already gone; the goal state ("no meeting") is reached.
        if ($response->status() === 404) {
            return true;
        }

        if ($response->failed()) {
            throw new GatewayRequestException($this->safeError('delete meeting', $response));
        }

        return true;
    }

    public function validateCredentials(): bool
    {
        try {
            Cache::forget($this->tokenCacheKey());

            return $this->accessToken() !== '';
        } catch (Throwable) {
            return false;
        }
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->accessToken())->acceptJson();
    }

    /** @throws GatewayRequestException when a token cannot be minted */
    private function accessToken(): string
    {
        $cached = Cache::get($this->tokenCacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $accountId = (string) $this->settings->zoom_account_id;
        $clientId = (string) $this->settings->zoom_client_id;
        $secret = $this->settings->decryptedZoomClientSecret();

        if ($accountId === '' || $clientId === '' || $secret === null) {
            throw new GatewayRequestException('Zoom credentials are missing or cannot be decrypted.');
        }

        $response = Http::asForm()
            ->withBasicAuth($clientId, $secret)
            ->post(self::TOKEN_URL, [
                'grant_type' => 'account_credentials',
                'account_id' => $accountId,
            ]);

        if ($response->failed()) {
            throw new GatewayRequestException($this->safeError('mint access token', $response));
        }

        $token = (string) $response->json('access_token', '');
        $expiresIn = (int) $response->json('expires_in', 3600);

        if ($token === '') {
            throw new GatewayRequestException('Zoom token response did not include an access token.');
        }

        Cache::put($this->tokenCacheKey(), $token, max(60, $expiresIn - self::TOKEN_EXPIRY_BUFFER_SECONDS));

        return $token;
    }

    /** Keyed on non-secret identifiers only — the secret never feeds a cache key. */
    private function tokenCacheKey(): string
    {
        return 'zoom.s2s_token.'.sha1($this->settings->zoom_account_id.'|'.$this->settings->zoom_client_id);
    }

    /**
     * The whitelist that keeps raw Zoom payloads out of the app: only
     * these six fields ever leave this class.
     *
     * @param  array<string, mixed>  $data
     * @return array{id: string, join_url: ?string, start_url: ?string, password: ?string, timezone: ?string, status: ?string}
     */
    private function sanitizeMeeting(array $data): array
    {
        return [
            'id' => (string) ($data['id'] ?? ''),
            'join_url' => $data['join_url'] ?? null,
            'start_url' => $data['start_url'] ?? null,
            'password' => $data['password'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'status' => $data['status'] ?? null,
        ];
    }

    /** Status code + Zoom's short message only — never headers, tokens, or a body dump. */
    private function safeError(string $action, Response $response): string
    {
        $message = (string) ($response->json('message') ?? '');

        return sprintf(
            'Zoom API failed to %s (HTTP %d)%s',
            $action,
            $response->status(),
            $message !== '' ? ': '.mb_substr($message, 0, 200) : '.',
        );
    }
}
