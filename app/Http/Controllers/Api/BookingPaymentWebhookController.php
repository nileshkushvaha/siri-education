<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Booking\Exceptions\BookingException;
use App\Booking\Services\BookingPaymentSettlementService;
use App\Http\Controllers\Controller;
use App\Models\BookingPayment;
use App\Payments\Services\PaymentService;
use App\Payments\Services\PaymentWebhookEventParser;
use App\Services\AuditTrailService;
use App\Services\Payment\PaymentWebhookSignatureService;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Provider → booking payment notifications.
 *
 * Transport only, and a deliberate mirror of
 * PackagePurchaseWebhookController: authenticity belongs to
 * PaymentWebhookSignatureService, parsing to PaymentWebhookEventParser,
 * attempt mechanics to PaymentService, and every financial decision to
 * BookingPaymentSettlementService.
 *
 * There is exactly ONE settlement path. Bookings collect through the
 * generic Payment attempt ledger, so a webhook is resolved by finding
 * the attempt it refers to — not by looking up a booking's own payment
 * reference, which is how the retired engine worked.
 *
 * Response contract:
 *
 *   401  unverifiable or malformed  — never retried, nothing mutated
 *   404  unknown provider
 *   200  processed / ignored — the provider should stop
 *   500  settlement failed and MUST be retried
 *
 * That last case matters: if settlement fails, answering 200 would tell
 * the provider to stop retrying money it has already collected.
 */
final class BookingPaymentWebhookController extends Controller
{
    public function __invoke(
        string $provider,
        Request $request,
        PaymentWebhookSignatureService $signatures,
        PaymentGatewaySettings $settings,
        PaymentWebhookEventParser $parser,
        PaymentService $payments,
        BookingPaymentSettlementService $settlement,
        AuditTrailService $audit,
    ): JsonResponse {
        abort_unless($parser->supports($provider), 404);

        // Before any state is read or written, and before the payload is
        // even decoded. A browser callback is never settlement evidence;
        // only a signed provider event is.
        if (! $signatures->isValid($provider, $request, $settings, PaymentWebhookSignatureService::PURPOSE_BOOKING)) {
            $audit->logSystem(
                'payments',
                'booking_webhook_signature_invalid',
                sprintf('Rejected an unverifiable %s booking payment webhook.', $provider),
                null,
                ['provider' => $provider, 'ip' => $request->ip()],
            );

            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        $payload = json_decode((string) $request->getContent(), true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'Malformed webhook payload.'], 401);
        }

        $event = $parser->parse($provider, $payload);

        if ($event === null) {
            return response()->json(['status' => 'ignored', 'reason' => 'unrecognised payload']);
        }

        $payment = $payments->findByProviderReference(
            $provider,
            $event->reference,
            $event->providerOrderId,
            $event->providerPaymentId,
        );

        // Unknown references are ordinary traffic (another domain's
        // payment, another environment). Never create records from a
        // webhook payload.
        if ($payment === null) {
            return response()->json(['status' => 'ignored', 'reason' => 'unknown reference']);
        }

        if ($payment->payable_type !== BookingPayment::PAYABLE_TYPE) {
            return response()->json(['status' => 'ignored', 'reason' => 'not a booking payment']);
        }

        try {
            $settled = $settlement->settle($payment, $event);
        } catch (BookingException $e) {
            // A refused mismatch or a failed local settlement. Either
            // way nothing was completed, and the provider should retry.
            return response()->json(['status' => 'retry', 'reason' => $e->getMessage()], 500);
        } catch (Throwable $e) {
            $audit->logSystem(
                'payments',
                'booking_settlement_failed',
                sprintf('Booking settlement failed and will be retried: %s', $e->getMessage()),
                $payment,
                ['payment_id' => $payment->id, 'provider' => $provider],
            );

            return response()->json(['status' => 'retry', 'reason' => 'settlement could not be completed'], 500);
        }

        return response()->json([
            'status' => $settled ? 'processed' : 'ignored',
            'event' => $event->type->value,
        ]);
    }
}
