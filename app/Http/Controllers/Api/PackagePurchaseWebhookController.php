<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentPackagePurchase;
use App\Package\Services\PackagePurchaseSettlementService;
use App\Payments\Services\PaymentService;
use App\Payments\Services\PaymentWebhookEventParser;
use App\Services\AuditTrailService;
use App\Services\Payment\PaymentWebhookSignatureService;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Provider -> package purchase notifications. A separate route and
 * controller from booking payments and wallet recharges, following the
 * house convention: a package is not a Booking and must never be
 * settled by pretending it is one.
 *
 * Transport only. Authenticity is PaymentWebhookSignatureService's job,
 * parsing is PaymentWebhookEventParser's, and every financial decision
 * belongs to PackagePurchaseSettlementService.
 *
 * Response contract, which differs deliberately from the wallet/booking
 * controllers in one important way:
 *
 *   401  unverifiable or malformed  — never retried, nothing mutated
 *   404  unknown provider
 *   200  processed / replayed / ignored — the provider should stop
 *   500  settlement failed and MUST be retried
 *
 * That last case is the point. If activation fails, the transaction
 * rolls back and we must NOT answer "success" — a false 200 would tell
 * the provider to stop retrying money it has already collected.
 */
final class PackagePurchaseWebhookController extends Controller
{
    public function __invoke(
        string $provider,
        Request $request,
        PaymentWebhookSignatureService $signatures,
        PaymentGatewaySettings $settings,
        PaymentWebhookEventParser $parser,
        PaymentService $payments,
        PackagePurchaseSettlementService $settlement,
        AuditTrailService $audit,
    ): JsonResponse {
        abort_unless($parser->supports($provider), 404);

        // Before any state is read or written, and before the payload
        // is even decoded.
        if (! $signatures->isValid($provider, $request, $settings)) {
            $audit->logSystem(
                'student_package_purchases',
                'package_webhook_signature_invalid',
                sprintf('Rejected an unverifiable %s package webhook.', $provider),
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

        if ($payment->payable_type !== StudentPackagePurchase::PAYABLE_TYPE) {
            return response()->json(['status' => 'ignored', 'reason' => 'not a package payment']);
        }

        try {
            $result = $settlement->settle($payment, $event);
        } catch (Throwable $e) {
            // Local settlement rolled back — whether that was a domain
            // refusal or a database failure, nothing was persisted.
            // Tell the provider to retry rather than losing the event.
            $audit->logSystem(
                'student_package_purchases',
                'package_settlement_failed',
                sprintf('Package settlement failed and will be retried: %s', $e->getMessage()),
                $payment,
                ['payment_id' => $payment->id, 'provider' => $provider],
            );

            return response()->json(['status' => 'retry', 'reason' => 'settlement could not be completed'], 500);
        }

        return response()->json([
            'status' => match (true) {
                $result->settled => 'processed',
                $result->replayed => 'replayed',
                default => 'ignored',
            },
            'reason' => $result->reason,
        ]);
    }
}
