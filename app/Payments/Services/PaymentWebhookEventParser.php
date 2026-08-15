<?php

declare(strict_types=1);

namespace App\Payments\Services;

use App\Payments\DTOs\VerifiedPaymentEvent;
use App\Payments\Enums\PaymentEventType;

/**
 * Phase 4B.3 — turns an ALREADY-SIGNATURE-VERIFIED provider payload
 * into a VerifiedPaymentEvent.
 *
 * Parsing lives here, in the generic payment layer, rather than in the
 * package domain: the shape of a Razorpay `payment.captured` or a
 * Stripe `payment_intent.succeeded` has nothing to do with packages,
 * and the next payable must not have to reimplement it.
 *
 * This class NEVER verifies authenticity — callers must have run
 * PaymentWebhookSignatureService first. It also never touches the
 * database, so a malformed or hostile payload cannot cause a write.
 *
 * The event mappings mirror the wallet webhook controller's existing,
 * production-proven choices, including Stripe's `amount_received`
 * subtlety.
 */
final class PaymentWebhookEventParser
{
    public const array SUPPORTED_PROVIDERS = ['razorpay', 'stripe'];

    /**
     * The local/testing provider. Checkout already supports it
     * (PaymentCheckoutService), so settlement must too — otherwise a
     * fake payment can be started and never completed, and local
     * development cannot exercise the real settlement path at all.
     *
     * It is gated to local/testing below because it carries no
     * verifiable signature: accepting it in production would let an
     * unsigned request settle real money.
     */
    public const string FAKE_PROVIDER = 'fake';

    public function supports(string $provider): bool
    {
        if ($provider === self::FAKE_PROVIDER) {
            return app()->environment(['local', 'testing']);
        }

        return in_array($provider, self::SUPPORTED_PROVIDERS, true);
    }

    /** @param array<string, mixed> $payload */
    public function parse(string $provider, array $payload): ?VerifiedPaymentEvent
    {
        return match (true) {
            $provider === 'razorpay' => $this->parseRazorpay($payload),
            $provider === 'stripe' => $this->parseStripe($payload),
            $provider === self::FAKE_PROVIDER && $this->supports($provider) => $this->parseFake($payload),
            default => null,
        };
    }

    /**
     * The fake provider's payload is our own flat shape, not a gateway's.
     *
     * @param  array<string, mixed>  $payload
     */
    private function parseFake(array $payload): ?VerifiedPaymentEvent
    {
        $reference = $payload['reference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            return null;
        }

        $type = match ((string) ($payload['event'] ?? '')) {
            'succeeded', 'captured' => PaymentEventType::Succeeded,
            'failed' => PaymentEventType::Failed,
            default => PaymentEventType::Ignored,
        };

        if ($type === PaymentEventType::Ignored) {
            return null;
        }

        return new VerifiedPaymentEvent(
            provider: self::FAKE_PROVIDER,
            type: $type,
            reference: $reference,
            providerOrderId: isset($payload['order_id']) ? (string) $payload['order_id'] : null,
            providerPaymentId: isset($payload['payment_id']) ? (string) $payload['payment_id'] : null,
            amountMinor: isset($payload['amount_minor']) ? (int) $payload['amount_minor'] : null,
            currencyCode: isset($payload['currency']) ? strtoupper((string) $payload['currency']) : null,
            reason: isset($payload['reason']) ? (string) $payload['reason'] : null,
        );
    }

    /** @param array<string, mixed> $payload */
    private function parseRazorpay(array $payload): ?VerifiedPaymentEvent
    {
        $entity = $payload['payload']['payment']['entity'] ?? null;

        if (! is_array($entity)) {
            return null;
        }

        return new VerifiedPaymentEvent(
            provider: 'razorpay',
            type: match ((string) ($payload['event'] ?? '')) {
                'payment.captured' => PaymentEventType::Succeeded,
                'payment.failed' => PaymentEventType::Failed,
                'payment.authorized' => PaymentEventType::Processing,
                default => PaymentEventType::Ignored,
            },
            reference: $this->stringOrNull($entity['notes']['payment_reference'] ?? null),
            providerOrderId: $this->stringOrNull($entity['order_id'] ?? null),
            providerPaymentId: $this->stringOrNull($entity['id'] ?? null),
            amountMinor: isset($entity['amount']) ? (int) $entity['amount'] : null,
            currencyCode: isset($entity['currency']) ? strtoupper((string) $entity['currency']) : null,
            occurredAt: now(),
            reason: $this->stringOrNull($entity['error_description'] ?? null),
        );
    }

    /** @param array<string, mixed> $payload */
    private function parseStripe(array $payload): ?VerifiedPaymentEvent
    {
        $intent = $payload['data']['object'] ?? null;

        if (! is_array($intent)) {
            return null;
        }

        $type = match ((string) ($payload['type'] ?? '')) {
            'payment_intent.succeeded' => PaymentEventType::Succeeded,
            'payment_intent.payment_failed' => PaymentEventType::Failed,
            'payment_intent.processing' => PaymentEventType::Processing,
            default => PaymentEventType::Ignored,
        };

        // `amount_received` is Stripe's authoritative captured amount;
        // `amount` is only what was requested and is not proof of what
        // was actually collected. Same rule the wallet flow follows.
        $amountMinor = $type === PaymentEventType::Succeeded
            ? ($intent['amount_received'] ?? null)
            : ($intent['amount'] ?? $intent['amount_received'] ?? null);

        return new VerifiedPaymentEvent(
            provider: 'stripe',
            type: $type,
            reference: is_array($intent['metadata'] ?? null)
                ? $this->stringOrNull($intent['metadata']['payment_reference'] ?? null)
                : null,
            // Stripe has one id for both roles: the PaymentIntent is
            // the order we created and the payment that settles it.
            providerOrderId: $this->stringOrNull($intent['id'] ?? null),
            providerPaymentId: $this->stringOrNull($intent['id'] ?? null),
            amountMinor: $amountMinor === null ? null : (int) $amountMinor,
            currencyCode: isset($intent['currency']) ? strtoupper((string) $intent['currency']) : null,
            occurredAt: now(),
            reason: is_array($intent['last_payment_error'] ?? null)
                ? $this->stringOrNull($intent['last_payment_error']['message'] ?? null)
                : null,
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
