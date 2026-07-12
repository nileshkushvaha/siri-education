<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Booking\Contracts\MeetingAttendanceProviderInterface;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\InvalidAttendanceWebhookException;
use App\Booking\Registry\MeetingProviderRegistry;
use App\Http\Controllers\Controller;
use App\Jobs\Meetings\ProcessMeetingAttendanceWebhook;
use App\Models\MeetingAttendanceProviderEvent;
use App\Settings\MeetingSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Provider → meeting attendance notifications. Signature is verified
 * BEFORE parsing; unknown/disabled/attendance-incapable providers 404;
 * malformed payloads 422 with a generic message (details go to the log,
 * never to the caller — and never with secrets or payloads). Replayed
 * events answer 200 "duplicate" so providers stop retrying. Accepted
 * envelopes are stored (sanitized, participant-hashed) and processed
 * on the queue.
 */
final class MeetingAttendanceWebhookController extends Controller
{
    public function __invoke(
        string $provider,
        Request $request,
        MeetingProviderRegistry $registry,
        MeetingSettings $settings,
    ): JsonResponse {
        abort_unless($settings->attendance_webhooks_enabled, 404);
        abort_unless($registry->has($provider), 404);

        try {
            $adapter = $registry->get($provider);
        } catch (BookingException) {
            abort(404);
        }

        abort_unless(
            $adapter instanceof MeetingAttendanceProviderInterface && $adapter->supportsAttendanceWebhooks(),
            404,
        );

        if (! $adapter->verifyAttendanceWebhookSignature($request)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        try {
            $webhook = $adapter->parseAttendanceWebhook($request);
        } catch (InvalidAttendanceWebhookException $e) {
            Log::warning('Rejected malformed attendance webhook', [
                'provider' => $provider,
                'reason' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Malformed webhook payload.'], 422);
        }

        try {
            $event = MeetingAttendanceProviderEvent::query()->create([
                'provider' => $webhook->provider,
                'provider_event_id' => $webhook->providerEventId,
                'meeting_reference' => $webhook->meetingReference,
                'source' => 'webhook',
                'signature_valid' => true,
                'processing_status' => 'received',
                'normalized_events' => array_map(fn ($e) => $e->toArray(), $webhook->events),
                'received_at' => now()->toImmutable()->utc(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Replayed webhook — safe success so the provider stops retrying.
            return response()->json(['status' => 'duplicate']);
        }

        ProcessMeetingAttendanceWebhook::dispatch($event->id);

        return response()->json(['status' => 'queued'], 202);
    }
}
