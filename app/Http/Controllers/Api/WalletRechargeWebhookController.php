<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WalletRecharge;
use App\Services\Payment\PaymentWebhookSignatureService;
use App\Settings\PaymentGatewaySettings;
use App\Wallet\Contracts\WalletRechargeServiceInterface;
use App\Wallet\DTOs\WalletRechargeProviderEvent;
use App\Wallet\Enums\WalletRechargeProviderEventType;
use App\Wallet\Exceptions\WalletException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Provider -> wallet recharge notifications. Razorpay and Stripe are
 * both wired for recharge; each provider's raw payload is parsed into
 * the same provider-agnostic WalletRechargeProviderEvent, which is the
 * only thing WalletRechargeService::processProviderEvent() ever sees —
 * settlement itself has no provider-specific code path. Idempotent:
 * repeated or out-of-state events answer 200 "ignored" so the provider
 * stops retrying; only unverifiable requests are rejected.
 */
final class WalletRechargeWebhookController extends Controller
{
    private const array SUPPORTED_PROVIDERS = ['razorpay', 'stripe'];

    public function __invoke(
        string $provider,
        Request $request,
        PaymentWebhookSignatureService $signatures,
        PaymentGatewaySettings $settings,
        WalletRechargeServiceInterface $recharges,
    ): JsonResponse {
        abort_unless(in_array($provider, self::SUPPORTED_PROVIDERS, true), 404);

        if (! $signatures->isValid($provider, $request, $settings)) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        $payload = json_decode((string) $request->getContent(), true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'Malformed webhook payload.'], 401);
        }

        $event = $provider === 'stripe' ? $this->parseStripeEvent($payload) : $this->parseRazorpayEvent($payload);

        if ($event === null) {
            return response()->json(['status' => 'ignored', 'reason' => 'unknown reference']);
        }

        if ($event->type === WalletRechargeProviderEventType::Ignored) {
            return response()->json(['status' => 'ignored', 'reason' => 'event type is not actionable']);
        }

        try {
            $result = $recharges->processProviderEvent($event);
        } catch (WalletException $e) {
            return response()->json(['status' => 'ignored', 'reason' => $e->getMessage()]);
        }

        return response()->json(['status' => $result->ignored ? 'ignored' : 'processed']);
    }

    /** @param  array<string, mixed>  $payload */
    private function parseRazorpayEvent(array $payload): ?WalletRechargeProviderEvent
    {
        $event = (string) ($payload['event'] ?? '');
        $entity = $payload['payload']['payment']['entity'] ?? null;

        if (! is_array($entity)) {
            return null;
        }

        $reference = $this->resolveRazorpayReference($entity);

        if ($reference === '') {
            return null;
        }

        $type = match ($event) {
            'payment.captured' => WalletRechargeProviderEventType::Captured,
            'payment.failed' => WalletRechargeProviderEventType::Failed,
            default => WalletRechargeProviderEventType::Ignored,
        };

        return new WalletRechargeProviderEvent(
            provider: 'razorpay',
            reference: $reference,
            providerOrderId: isset($entity['order_id']) ? (string) $entity['order_id'] : null,
            providerPaymentId: isset($entity['id']) ? (string) $entity['id'] : null,
            amountMinor: (int) ($entity['amount'] ?? -1),
            currencyCode: strtoupper((string) ($entity['currency'] ?? '')),
            type: $type,
            reason: is_string($entity['error_description'] ?? null) ? $entity['error_description'] : null,
        );
    }

    /** @param  array<string, mixed>  $entity */
    private function resolveRazorpayReference(array $entity): string
    {
        $reference = (string) ($entity['notes']['wallet_recharge_reference'] ?? '');

        if ($reference !== '' || ! isset($entity['order_id'])) {
            return $reference;
        }

        // Fall back to the order id when notes are absent (mirrors
        // RazorpayPaymentProvider::parseWebhook()'s identical fallback).
        $recharge = WalletRecharge::query()->where('provider_order_id', $entity['order_id'])->first();

        return $recharge?->idempotency_key ?? '';
    }

    /** @param  array<string, mixed>  $payload */
    private function parseStripeEvent(array $payload): ?WalletRechargeProviderEvent
    {
        $type = (string) ($payload['type'] ?? '');
        $intent = $payload['data']['object'] ?? null;

        if (! is_array($intent)) {
            return null;
        }

        $reference = $this->resolveStripeReference($intent);

        if ($reference === '') {
            return null;
        }

        $normalized = match ($type) {
            'payment_intent.succeeded' => WalletRechargeProviderEventType::Captured,
            'payment_intent.payment_failed' => WalletRechargeProviderEventType::Failed,
            'payment_intent.processing' => WalletRechargeProviderEventType::Processing,
            default => WalletRechargeProviderEventType::Ignored,
        };

        // amount_received is Stripe's authoritative captured amount;
        // amount is only the requested amount and is not sufficient
        // proof of what was actually collected.
        $amountMinor = $normalized === WalletRechargeProviderEventType::Captured
            ? (int) ($intent['amount_received'] ?? -1)
            : (int) ($intent['amount'] ?? $intent['amount_received'] ?? -1);

        return new WalletRechargeProviderEvent(
            provider: 'stripe',
            reference: $reference,
            providerOrderId: isset($intent['id']) ? (string) $intent['id'] : null,
            providerPaymentId: isset($intent['id']) ? (string) $intent['id'] : null,
            amountMinor: $amountMinor,
            currencyCode: strtoupper((string) ($intent['currency'] ?? '')),
            type: $normalized,
            reason: is_array($intent['last_payment_error'] ?? null)
                ? ($intent['last_payment_error']['message'] ?? null)
                : null,
        );
    }

    /** @param  array<string, mixed>  $intent */
    private function resolveStripeReference(array $intent): string
    {
        $reference = is_array($intent['metadata'] ?? null)
            ? (string) ($intent['metadata']['wallet_recharge_reference'] ?? '')
            : '';

        if ($reference !== '' || ! isset($intent['id'])) {
            return $reference;
        }

        // Fall back to the PaymentIntent id when metadata is absent
        // (mirrors StripePaymentProvider::parseWebhook()'s identical fallback).
        $recharge = WalletRecharge::query()->where('provider_order_id', $intent['id'])->first();

        return $recharge?->idempotency_key ?? '';
    }
}
