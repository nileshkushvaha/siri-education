<?php

declare(strict_types=1);

namespace App\Wallet\Services;

use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Exceptions\GatewayRequestException;
use App\Models\WalletRecharge;
use App\Services\AuditTrailService;
use App\Services\Payment\PaymentWebhookSignatureService;
use App\Settings\PaymentGatewaySettings;
use App\Wallet\Contracts\WalletRechargeServiceInterface;
use App\Wallet\DTOs\WalletRechargeProviderEvent;
use App\Wallet\Enums\WalletRechargeProviderEventType;
use App\Wallet\Enums\WalletRechargeStatus;
use App\Wallet\Exceptions\WalletException;

/**
 * The provider-neutral-in-spirit (Razorpay-only in practice this
 * phase) reconciliation sweep for wallet recharges — deliberately
 * small: no separate issue-tracking table, unlike
 * BookingPaymentReconciliationService. Every state transition here
 * goes through WalletRechargeService::processProviderEvent()/
 * retryPendingCredit(), never a separate financial-effect code path.
 * SRS §13.33: this is the sweep that detects "payment succeeded but
 * wallet credit failed" and retries it, and "payment success but
 * callback delayed" (a captured order the webhook never reported).
 */
final class WalletRechargeReconciliationService
{
    /** How long a non-terminal recharge waits before its first reconciliation check. */
    private const int DUE_AFTER_MINUTES = 10;

    public function __construct(
        private readonly WalletRechargeServiceInterface $recharges,
        private readonly RazorpayGatewayClient $razorpay,
        private readonly StripeGatewayClient $stripe,
        private readonly PaymentGatewaySettings $gatewaySettings,
        private readonly AuditTrailService $audit,
    ) {}

    public function reconcileDue(int $limit = 200): int
    {
        $cutoff = now()->subMinutes(self::DUE_AFTER_MINUTES);

        $recharges = WalletRecharge::query()
            ->reconciliationDue($cutoff)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $examined = 0;

        foreach ($recharges as $recharge) {
            $this->reconcileOne($recharge);
            $examined++;
        }

        return $examined;
    }

    public function reconcileOne(WalletRecharge $recharge): WalletRecharge
    {
        if ($recharge->status->needsCreditRetry()) {
            return $this->retryCredit($recharge);
        }

        if ($recharge->status->isAwaitingSettlement()) {
            return $this->pollProviderOrder($recharge);
        }

        return $recharge;
    }

    private function retryCredit(WalletRecharge $recharge): WalletRecharge
    {
        try {
            $result = $this->recharges->retryPendingCredit($recharge);
        } catch (WalletException) {
            $this->markSynced($recharge);

            return $recharge->refresh();
        }

        if ($result->status === WalletRechargeStatus::CreditFailed) {
            // A repeated CreditFailed outcome across sweeps is the
            // operationally significant signal SRS §13.33 asks for —
            // captured provider money that still cannot reach the
            // wallet needs an operator, not another silent retry.
            $this->audit->logSystem(
                'wallet_recharges',
                'reconciliation_credit_retry_failed',
                sprintf('Wallet recharge %s remains uncredited after a reconciliation retry.', $recharge->idempotency_key),
                $result,
                ['failure_code' => $result->failure_code, 'amount_minor' => $result->amount_minor, 'currency_code' => $result->currency_code],
            );
        }

        $this->markSynced($result);

        return $result;
    }

    private function pollProviderOrder(WalletRecharge $recharge): WalletRecharge
    {
        if ($recharge->provider_order_id === null) {
            $this->markSynced($recharge);

            return $recharge;
        }

        return match ($recharge->provider) {
            'razorpay' => $this->pollRazorpayOrder($recharge),
            'stripe' => $this->pollStripeIntent($recharge),
            default => tap($recharge, fn (WalletRecharge $r) => $this->markSynced($r)),
        };
    }

    private function pollRazorpayOrder(WalletRecharge $recharge): WalletRecharge
    {
        $keyId = (string) $this->gatewaySettings->razorpay_key_id;
        $keySecret = (string) PaymentWebhookSignatureService::decryptSecret($this->gatewaySettings, 'razorpay_key_secret');

        try {
            $order = $this->razorpay->fetchOrder($keyId, $keySecret, $recharge->provider_order_id);
        } catch (GatewayRequestException) {
            $this->markSynced($recharge);

            return $recharge->refresh();
        }

        $status = (string) ($order['status'] ?? '');

        // Never guess: only an authoritative "paid" status from the
        // provider's own order lookup ever settles a recharge here —
        // this is exactly the "captured but the webhook never arrived"
        // case SRS §13.33 requires reconciliation to catch.
        if ($status === 'paid') {
            try {
                $this->recharges->processProviderEvent(new WalletRechargeProviderEvent(
                    provider: 'razorpay',
                    reference: $recharge->idempotency_key,
                    providerOrderId: $recharge->provider_order_id,
                    providerPaymentId: $recharge->provider_payment_id,
                    amountMinor: $recharge->amount_minor,
                    currencyCode: $recharge->currency_code,
                    type: WalletRechargeProviderEventType::Captured,
                ));
            } catch (WalletException) {
                $this->markSynced($recharge);

                return $recharge->refresh();
            }

            return $recharge->refresh();
        }

        if ($recharge->status === WalletRechargeStatus::ProviderCreated) {
            $recharge->forceFill(['status' => WalletRechargeStatus::AwaitingConfirmation])->save();
        }

        $this->markSynced($recharge);

        return $recharge->refresh();
    }

    private function pollStripeIntent(WalletRecharge $recharge): WalletRecharge
    {
        $secretKey = (string) PaymentWebhookSignatureService::decryptSecret($this->gatewaySettings, 'stripe_secret_key');

        try {
            $intent = $this->stripe->retrievePaymentIntent($secretKey, $recharge->provider_order_id);
        } catch (GatewayRequestException) {
            $this->markSynced($recharge);

            return $recharge->refresh();
        }

        $status = (string) ($intent['status'] ?? '');

        // Never guess: only an authoritative "succeeded" status from
        // Stripe's own intent lookup ever settles a recharge here — the
        // "captured but the webhook never arrived" case SRS §13.33
        // requires reconciliation to catch.
        if ($status === 'succeeded') {
            try {
                $this->recharges->processProviderEvent(new WalletRechargeProviderEvent(
                    provider: 'stripe',
                    reference: $recharge->idempotency_key,
                    providerOrderId: $recharge->provider_order_id,
                    providerPaymentId: $recharge->provider_payment_id,
                    amountMinor: (int) ($intent['amount_received'] ?? -1),
                    currencyCode: strtoupper((string) ($intent['currency'] ?? $recharge->currency_code)),
                    type: WalletRechargeProviderEventType::Captured,
                ));
            } catch (WalletException) {
                $this->markSynced($recharge);

                return $recharge->refresh();
            }

            return $recharge->refresh();
        }

        // A canceled intent is Stripe's own authoritative terminal
        // failure — never guessed from elapsed time.
        if ($status === 'canceled') {
            $this->recharges->markProviderFailure(
                'stripe',
                $recharge->idempotency_key,
                'stripe_canceled',
                'Stripe reported the payment intent as canceled.',
            );

            return $recharge->refresh();
        }

        if ($recharge->status === WalletRechargeStatus::ProviderCreated) {
            $recharge->forceFill(['status' => WalletRechargeStatus::AwaitingConfirmation])->save();
        }

        $this->markSynced($recharge);

        return $recharge->refresh();
    }

    private function markSynced(WalletRecharge $recharge): void
    {
        WalletRecharge::query()->whereKey($recharge->id)->update(['last_synced_at' => now()]);
    }
}
