<?php

declare(strict_types=1);

namespace App\Booking\Meetings\Concerns;

use App\Booking\Exceptions\InvalidRecordingWebhookException;
use Illuminate\Http\Request;

/**
 * Zoom's current webhook authenticity scheme, implemented exactly as
 * Zoom documents it:
 *
 *   message   = "v0:" + x-zm-request-timestamp + ":" + RAW request body
 *   signature = "v0=" + hex( HMAC-SHA256(message, webhook secret token) )
 *   compare against the x-zm-signature header
 *
 * Two details are load-bearing and easy to get wrong:
 *
 *  - the RAW body must be hashed, never a re-encoded array. Any
 *    round-trip through json_decode/json_encode reorders keys or
 *    changes escaping and every signature fails.
 *  - the comparison must be constant-time (hash_equals), otherwise the
 *    check leaks the expected signature a byte at a time.
 *
 * Zoom also validates endpoint ownership before it will deliver events:
 * it posts an `endpoint.url_validation` event carrying a plainToken,
 * and expects that token echoed back alongside its HMAC within three
 * seconds. That challenge is answered here too, since it uses the same
 * secret and must not be confused with a real event.
 */
trait VerifiesZoomWebhooks
{
    private const ZOOM_SIGNATURE_HEADER = 'x-zm-signature';

    private const ZOOM_TIMESTAMP_HEADER = 'x-zm-request-timestamp';

    private const ZOOM_URL_VALIDATION_EVENT = 'endpoint.url_validation';

    /**
     * How far out of step a webhook timestamp may be before it is
     * treated as a replay. Zoom does not mandate a window; five
     * minutes matches the tolerance used elsewhere in this application
     * and is generous enough for ordinary clock drift.
     */
    private const ZOOM_TIMESTAMP_TOLERANCE_SECONDS = 300;

    private function verifyZoomSignature(Request $request, ?string $secret): bool
    {
        if (blank($secret)) {
            // Fail closed: no secret configured means no webhook can be
            // trusted, so none is accepted.
            return false;
        }

        $signature = (string) $request->header(self::ZOOM_SIGNATURE_HEADER, '');
        $timestamp = (string) $request->header(self::ZOOM_TIMESTAMP_HEADER, '');

        if ($signature === '' || $timestamp === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        // Zoom's timestamps are milliseconds since the epoch.
        if (abs(now()->getTimestamp() - (int) ($timestamp / 1000)) > self::ZOOM_TIMESTAMP_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = 'v0='.hash_hmac('sha256', sprintf('v0:%s:%s', $timestamp, $request->getContent()), $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Answers Zoom's endpoint ownership challenge, or null when this
     * request is an ordinary event.
     *
     * @return array{plainToken: string, encryptedToken: string}|null
     */
    private function zoomChallengeResponse(Request $request, ?string $secret): ?array
    {
        if ($request->input('event') !== self::ZOOM_URL_VALIDATION_EVENT) {
            return null;
        }

        $plainToken = $request->input('payload.plainToken');

        if (blank($secret) || ! is_string($plainToken) || $plainToken === '') {
            throw new InvalidRecordingWebhookException('Zoom URL validation challenge is missing a plain token.');
        }

        return [
            'plainToken' => $plainToken,
            'encryptedToken' => hash_hmac('sha256', $plainToken, $secret),
        ];
    }
}
