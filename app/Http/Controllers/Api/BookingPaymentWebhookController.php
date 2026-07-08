<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Enums\PaymentWebhookEvent;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\InvalidPaymentWebhookException;
use App\Booking\Registry\PaymentProviderRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Provider → booking payment notifications. Idempotent: repeated or
 * out-of-state events answer 200 "ignored" so providers stop retrying;
 * only unverifiable requests are rejected.
 */
final class BookingPaymentWebhookController extends Controller
{
    public function __invoke(
        string $provider,
        Request $request,
        PaymentProviderRegistry $providers,
        BookingRepositoryInterface $bookings,
        BookingPaymentServiceInterface $payments,
    ): JsonResponse {
        abort_unless($providers->has($provider), 404);

        try {
            $webhook = $providers->get($provider)->parseWebhook($request);
        } catch (InvalidPaymentWebhookException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        }

        $booking = $bookings->findByPaymentReference($webhook->reference);

        if ($booking === null) {
            return response()->json(['status' => 'ignored', 'reason' => 'unknown reference']);
        }

        try {
            match ($webhook->event) {
                PaymentWebhookEvent::Succeeded => $payments->markPaid($booking, $webhook->reference),
                PaymentWebhookEvent::Failed => $payments->markFailed($booking, $webhook->reference, $webhook->reason),
                PaymentWebhookEvent::Refunded => $payments->recordRefund($booking, $webhook->reason),
                PaymentWebhookEvent::Ignored => throw new BookingException('Event type is not actionable.'),
            };
        } catch (BookingException $e) {
            // Already processed / out-of-state — acknowledge so the provider stops retrying.
            return response()->json(['status' => 'ignored', 'reason' => $e->getMessage()]);
        }

        return response()->json(['status' => 'processed', 'event' => $webhook->event->value]);
    }
}
