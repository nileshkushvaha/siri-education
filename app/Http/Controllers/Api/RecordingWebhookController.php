<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Booking\Contracts\RecordingWebhookProvider;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\InvalidRecordingWebhookException;
use App\Booking\Jobs\CaptureLessonRecordingJob;
use App\Booking\Registry\MeetingProviderRegistry;
use App\Http\Controllers\Controller;
use App\Models\BookingMeeting;
use App\Models\Recording;
use App\Models\RecordingProviderEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Provider → "a recording is ready" notifications. Provider-neutral by
 * route parameter, exactly like the attendance and payment webhooks:
 * any provider implementing RecordingWebhookProvider is reachable here,
 * and nothing in this class names Zoom.
 *
 * The handler does the minimum trusted work and nothing else:
 *
 *     verify signature  →  identify the lesson  →  record the delivery
 *     →  dispatch after commit  →  return
 *
 * It NEVER downloads a recording. A class video can take minutes to
 * transfer; doing that inside an HTTP request would blow Zoom's
 * three-second acknowledgement budget, hold a PHP worker hostage, and
 * guarantee redelivery storms. The queued job owns the transfer.
 *
 * The webhook is an OPTIMIZATION, not the guarantee. Everything it does
 * the bounded recordings:capture sweep also does, so a webhook that was
 * never delivered — or arrived while the app was down — costs latency,
 * never a recording.
 */
final class RecordingWebhookController extends Controller
{
    public function __invoke(
        string $provider,
        Request $request,
        MeetingProviderRegistry $registry,
    ): JsonResponse {
        // An unknown, unregistered or webhook-incapable provider is
        // indistinguishable from a wrong URL — 404, revealing nothing
        // about what this deployment has configured.
        abort_unless($registry->has($provider), 404);

        try {
            $adapter = $registry->get($provider);
        } catch (BookingException) {
            abort(404);
        }

        abort_unless(
            $adapter instanceof RecordingWebhookProvider && $adapter->supportsRecordingWebhooks(),
            404,
        );

        // Authenticity FIRST — before the body is parsed, trusted, or
        // allowed to influence anything at all.
        if (! $adapter->verifyRecordingWebhookSignature($request)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        try {
            // Endpoint-ownership challenges are answered inline: they
            // are not events, carry no meeting, and must be echoed back
            // within the provider's own short timeout.
            $challenge = $adapter->recordingWebhookChallengeResponse($request);

            if ($challenge !== null) {
                return response()->json($challenge);
            }

            $webhook = $adapter->parseRecordingWebhook($request);
        } catch (InvalidRecordingWebhookException $e) {
            // The reason goes to the log; the caller gets a generic
            // message. Never echo payload fragments back to a webhook
            // sender — they may carry credentials.
            Log::warning('Rejected malformed recording webhook', [
                'provider' => $provider,
                'reason' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Malformed webhook payload.'], 422);
        }

        $meeting = $webhook->meetingReference !== null
            ? BookingMeeting::query()
                ->where('provider', $webhook->provider)
                ->where('provider_meeting_id', $webhook->meetingReference)
                ->first()
            : null;

        $recording = $meeting !== null
            ? Recording::query()->where('booking_meeting_id', $meeting->getKey())->first()
            : null;

        try {
            RecordingProviderEvent::query()->create([
                'provider' => $webhook->provider,
                'provider_event_id' => $webhook->eventId,
                'event_type' => $webhook->eventType,
                'meeting_reference' => $webhook->meetingReference,
                'booking_meeting_id' => $meeting?->getKey(),
                'recording_id' => $recording?->getKey(),
                'processing_status' => $this->outcome($webhook->recordingReady, $meeting, $recording),
                'received_at' => now(),
                'processed_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A redelivery of an event already seen. Answering 200 is
            // what stops the provider retrying; the unique index — not
            // a cache or an in-memory set — is the actual guarantee.
            return response()->json(['status' => 'duplicate']);
        }

        // Only a genuinely ingestable event for a lesson we recognise
        // gets queued. Everything else is acknowledged and dropped:
        // Zoom emits recording events SIRI has no use for, and 200 is
        // the correct answer to all of them.
        if ($webhook->recordingReady && $recording !== null) {
            CaptureLessonRecordingJob::dispatch($recording->getKey())->afterCommit();
        }

        return response()->json(['status' => 'accepted']);
    }

    /** A safe, non-identifying outcome label for the operational record. */
    private function outcome(bool $ready, ?BookingMeeting $meeting, ?Recording $recording): string
    {
        return match (true) {
            ! $ready => 'ignored_event',
            $meeting === null => 'unknown_meeting',
            $recording === null => 'no_recording_registered',
            default => 'queued',
        };
    }
}
