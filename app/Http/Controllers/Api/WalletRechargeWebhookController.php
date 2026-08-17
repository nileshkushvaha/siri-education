<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WalletRecharge;
use App\Payments\Services\PaymentService;
use App\Payments\Services\PaymentWebhookEventParser;
use App\Services\AuditTrailService;
use App\Services\Payment\PaymentWebhookSignatureService;
use App\Settings\PaymentGatewaySettings;
use App\Wallet\Services\WalletRechargeSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Provider -> wallet recharge notifications.
 *
 * TRANSPORT ONLY. Authenticity is PaymentWebhookSignatureService's job,
 * parsing is PaymentWebhookEventParser's, attempt resolution is
 * PaymentService's, and every financial decision belongs to
 * WalletRechargeSettlementService.
 *
 * This controller previously carried its own Razorpay and Stripe payload
 * parsers — a second implementation of a question the generic parser
 * already answers for bookings and packages, and therefore a second
 * thing that could be wrong about whether money arrived. Those are gone.
 *
 * A separate ROUTE from bookings and packages is deliberate and matches
 * the house convention: each payable domain owns its endpoint, and each
 * endpoint has its own webhook secret scope (PURPOSE_WALLET), so a
 * leaked recharge secret cannot become authority to settle lessons. What
 * is shared is everything worth sharing — the signature service, the
 * parser, the gateway clients, and the Payment ledger.
 *
 * Response contract, matching PackagePurchaseWebhookController:
 *
 *   401  unverifiable or malformed  — never retried, nothing mutated
 *   404  unknown provider
 *   200  processed / replayed / ignored — the provider should stop
 *   500  settlement failed and MUST be retried
 *
 * A credit failure is deliberately a 200, not a 500. The provider has
 * already collected the money and has nothing left to do; retrying the
 * webhook would not unfreeze a frozen wallet. That case is durable,
 * operator-visible and retried locally instead.
 */
final class WalletRechargeWebhookController extends Controller
{
    public function __invoke(
        string $provider,
        Request $request,
        PaymentWebhookSignatureService $signatures,
        PaymentGatewaySettings $settings,
        PaymentWebhookEventParser $parser,
        PaymentService $payments,
        WalletRechargeSettlementService $settlement,
        AuditTrailService $audit,
    ): JsonResponse {
        abort_unless($parser->supports($provider), 404);

        // Before any state is read or written, and before the payload is
        // even decoded.
        if (! $signatures->isValid($provider, $request, $settings, PaymentWebhookSignatureService::PURPOSE_WALLET)) {
            $audit->logSystem(
                'wallet_recharges',
                'wallet_webhook_signature_invalid',
                sprintf('Rejected an unverifiable %s wallet recharge webhook.', $provider),
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

        // The correlation guard: a signed event for a booking or package
        // payment must never be able to settle a wallet recharge. The
        // link comes from the canonical ledger, never from the payload.
        if ($payment->payable_type !== WalletRecharge::PAYABLE_TYPE) {
            return response()->json(['status' => 'ignored', 'reason' => 'not a wallet recharge payment']);
        }

        try {
            $result = $settlement->settle($payment, $event);
        } catch (Throwable $e) {
            // Local settlement rolled back — nothing was persisted. Tell
            // the provider to retry rather than losing the event.
            $audit->logSystem(
                'wallet_recharges',
                'wallet_settlement_failed',
                sprintf('Wallet recharge settlement failed and will be retried: %s', $e->getMessage()),
                $payment,
                ['payment_id' => $payment->id, 'provider' => $provider],
            );

            return response()->json(['status' => 'retry', 'reason' => 'settlement could not be completed'], 500);
        }

        return response()->json([
            'status' => match (true) {
                $result->settled => 'processed',
                $result->replayed => 'replayed',
                $result->creditFailed => 'credit_failed',
                default => 'ignored',
            },
            'reason' => $result->reason,
        ]);
    }
}
