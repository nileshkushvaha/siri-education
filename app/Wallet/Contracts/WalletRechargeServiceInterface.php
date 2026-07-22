<?php

declare(strict_types=1);

namespace App\Wallet\Contracts;

use App\Models\User;
use App\Models\WalletRecharge;
use App\Wallet\DTOs\WalletRechargeCheckoutData;
use App\Wallet\DTOs\WalletRechargeProviderEvent;
use App\Wallet\DTOs\WalletRechargeSettlementResult;
use App\Wallet\Exceptions\WalletException;

/**
 * Wallet recharge via a payment gateway (SRS §13.11/§13.12) — a
 * student adding funds to their own wallet, independent of any
 * booking. Deliberately not PaymentProviderInterface: every method
 * there is typed to Booking. This orchestrates the same generic
 * gateway SDK clients (RazorpayGatewayClient et al.) that
 * PaymentProviderInterface implementations use internally, via
 * PaymentProviderResolver for provider selection only.
 *
 * The wallet is never credited before a provider has authoritatively
 * confirmed capture — initiate() never touches the ledger.
 */
interface WalletRechargeServiceInterface
{
    /**
     * Resolves/creates the student's own wallet, validates amount and
     * provider eligibility, and creates the provider order/intent.
     * $amountMinor is already-validated integer minor units — never a
     * raw client string.
     *
     * @throws WalletException when the actor is ineligible, the wallet
     *                         feature is disabled, the wallet is not usable for a new
     *                         recharge, the amount is out of the configured range, or no
     *                         recharge-capable provider is available for the wallet's currency
     */
    public function initiate(User $student, int $amountMinor): WalletRechargeCheckoutData;

    /**
     * Razorpay's client-side Checkout.js success callback — a fast
     * path, never trusted alone. Verifies the order_id|payment_id HMAC
     * signature, re-checks that the order belongs to $student's own
     * recharge, then funnels into the same settlement path the webhook
     * uses. The signed webhook remains the durable, authoritative
     * fallback.
     *
     * @throws WalletException when the signature is invalid, the order does not belong to this student, or the amount/currency disagree
     */
    public function verifyRazorpayCheckout(User $student, string $orderId, string $paymentId, string $signature): WalletRecharge;

    /**
     * The single authoritative settlement path every verified provider
     * event (webhook or browser-verify) ultimately funnels through.
     * Idempotent: a recharge already Succeeded returns an ignored
     * result rather than crediting twice. A provider-captured payment
     * that cannot be credited right now (wallet closed/ownership
     * changed) is recorded as CreditFailed, never silently discarded.
     */
    public function processProviderEvent(WalletRechargeProviderEvent $event): WalletRechargeSettlementResult;

    /**
     * Reconciliation's retry entry point for a recharge stuck in
     * CreditPending or CreditFailed — re-attempts the same locked
     * credit step processProviderEvent() uses. A repeat call after a
     * prior success is a no-op (idempotency key guards the ledger).
     *
     * @throws WalletException when the recharge is not in a retryable state
     */
    public function retryPendingCredit(WalletRecharge $recharge): WalletRecharge;

    /**
     * A definitive, non-capturable provider outcome (failed/cancelled/
     * expired) — never called for a payment that was ever captured.
     *
     * @throws WalletException when the reference does not match a recharge awaiting settlement
     */
    public function markProviderFailure(string $provider, string $reference, string $failureCode, ?string $reason = null): WalletRecharge;
}
