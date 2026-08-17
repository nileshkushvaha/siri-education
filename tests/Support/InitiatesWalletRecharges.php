<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Payment;
use App\Models\User;
use App\Models\WalletRecharge;
use App\Payments\DTOs\VerifiedPaymentEvent;
use App\Payments\Enums\PaymentEventType;
use App\Wallet\Contracts\WalletRechargeServiceInterface;
use App\Wallet\Services\WalletRechargeSettlementService;

/**
 * Drives a wallet recharge the way production does, so a fixture can
 * never describe a state the real code could not have produced.
 *
 * Every helper here goes through the canonical services — never a
 * hand-built `wallet_recharges` row, and never a hand-built `payments`
 * row. That matters more than usual after the payment-ledger cutover:
 * the whole point is that provider identity lives on `Payment` and
 * nowhere else, and a test that assembled its own rows could quietly
 * reintroduce the duplicate-identity architecture the cutover removed.
 *
 * Mirrors PackagePurchaseSettlementTest::purchaseWithOpenAttempt()'s
 * shape deliberately — one house style for payable-backed payments.
 */
trait InitiatesWalletRecharges
{
    /**
     * A recharge with a live open Payment attempt, exactly as
     * `Add Money` produces.
     *
     * @return array{0: WalletRecharge, 1: Payment}
     */
    protected function initiateRecharge(User $student, int $amountMinor = 50000): array
    {
        $checkout = app(WalletRechargeServiceInterface::class)->initiate($student, $amountMinor);

        // PaymentCheckoutData::$reference is the PAYMENT's reference
        // (`PAY-…`), not the recharge's business reference (`WRCH-…`) —
        // the same two-reference shape package purchase has. The
        // recharge is therefore reached through the attempt's payable
        // link, which is also the only link that exists now.
        $payment = Payment::query()->whereKey($checkout->paymentId)->sole();
        $recharge = WalletRecharge::query()->whereKey($payment->payable_id)->sole();

        return [$recharge, $payment];
    }

    /**
     * The authoritative "the provider captured this" event, built from
     * the ATTEMPT's own values by default.
     *
     * Overriding $amountMinor/$currencyCode is how a test expresses a
     * provider that disagrees with what SIRI recorded — the case that
     * must never settle.
     */
    protected function capturedEvent(
        Payment $payment,
        ?int $amountMinor = null,
        ?string $currencyCode = null,
        ?string $provider = null,
        ?string $providerPaymentId = null,
    ): VerifiedPaymentEvent {
        return new VerifiedPaymentEvent(
            provider: $provider ?? (string) $payment->provider,
            type: PaymentEventType::Succeeded,
            reference: $payment->idempotency_key,
            providerOrderId: $payment->provider_order_id,
            providerPaymentId: $providerPaymentId ?? 'pay_'.strtoupper(substr((string) $payment->id, 0, 12)),
            amountMinor: $amountMinor ?? (int) $payment->amount_minor,
            currencyCode: $currencyCode ?? (string) $payment->currency_code,
        );
    }

    protected function failedEvent(Payment $payment, ?string $reason = 'Card declined.'): VerifiedPaymentEvent
    {
        return new VerifiedPaymentEvent(
            provider: (string) $payment->provider,
            type: PaymentEventType::Failed,
            reference: $payment->idempotency_key,
            providerOrderId: $payment->provider_order_id,
            amountMinor: (int) $payment->amount_minor,
            currencyCode: (string) $payment->currency_code,
            reason: $reason,
        );
    }

    protected function processingEvent(Payment $payment): VerifiedPaymentEvent
    {
        return new VerifiedPaymentEvent(
            provider: (string) $payment->provider,
            type: PaymentEventType::Processing,
            reference: $payment->idempotency_key,
            providerOrderId: $payment->provider_order_id,
            amountMinor: (int) $payment->amount_minor,
            currencyCode: (string) $payment->currency_code,
        );
    }

    /** The single settlement path both the webhook and reconciliation reach. */
    protected function settle(Payment $payment, VerifiedPaymentEvent $event): mixed
    {
        return app(WalletRechargeSettlementService::class)->settle($payment, $event);
    }

    /** Initiate and settle in one step, for tests whose subject is what happens AFTER a successful recharge. */
    protected function completedRecharge(User $student, int $amountMinor = 50000): WalletRecharge
    {
        [$recharge, $payment] = $this->initiateRecharge($student, $amountMinor);

        $this->settle($payment, $this->capturedEvent($payment));

        return $recharge->refresh();
    }
}
