<?php

declare(strict_types=1);

namespace App\Wallet\Contracts;

use App\Models\User;
use App\Models\WalletRecharge;
use App\Payments\DTOs\PaymentCheckoutData;
use App\Wallet\Exceptions\WalletException;

/**
 * Wallet recharge (SRS §13.11/§13.12) — a student adding funds to their
 * own wallet, independent of any booking.
 *
 * The DOMAIN half only: who may recharge, for how much, in which
 * currency. Implementations must hold no gateway client, no provider
 * secret, and no provider name in a conditional — creating the external
 * order and owning provider identity belong to the generic payment
 * kernel, and a wallet recharge is a `Payable` like any other.
 *
 * Nothing on this contract credits a wallet. A wallet moves only when an
 * authenticated provider event reaches WalletRechargeSettlementService.
 */
interface WalletRechargeServiceInterface
{
    /**
     * The collection transaction type a wallet recharge presents to
     * PaymentCollectionEligibilityService, matching what providers
     * declare in PaymentProviderCapabilities::$supportedTransactionTypes.
     * Lives on the contract so read-only consumers (the student wallet
     * screen's availability preview) can ask the same question without
     * depending on the concrete service.
     */
    public const string TRANSACTION_TYPE = 'wallet_recharge';

    /**
     * Validates eligibility and amount, creates the WalletRecharge
     * domain record, and opens checkout through the generic payment
     * kernel.
     *
     * Every eligibility question is answered BEFORE any money state
     * exists: a refused recharge creates no WalletRecharge, no Payment,
     * and reaches no provider. $amountMinor is already-validated integer
     * minor units — never a raw client string — and the resulting
     * Payment takes its amount and currency from the recharge's own
     * snapshot, never from the request.
     *
     * @throws WalletException when the actor is ineligible, the wallet feature or
     *                         market is unavailable, the wallet is not usable for a new
     *                         recharge, the amount is outside the currency's configured
     *                         range, or the gateway rejects the order
     */
    public function initiate(User $student, int $amountMinor): PaymentCheckoutData;

    /**
     * Re-presents the open payment attempt for a recharge the student
     * already started, instead of opening a second one. Ownership is
     * derived from the recharge, never from the request.
     *
     * @throws WalletException when the recharge is not this student's, or has nothing open
     */
    public function resumeCheckout(User $student, WalletRecharge $recharge): PaymentCheckoutData;
}
