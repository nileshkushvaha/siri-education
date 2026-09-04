<?php

declare(strict_types=1);

namespace App\Wallet\Services;

use App\Booking\Contracts\PaymentCollectionEligibilityServiceInterface;
use App\Country\Enums\CountryFeature;
use App\Country\Services\CountryFeatureResolver;
use App\Country\Services\CountryResolver;
use App\Models\Currency;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletRecharge;
use App\Payments\DTOs\PaymentCheckoutData;
use App\Payments\Exceptions\PaymentException;
use App\Payments\Services\PaymentCheckoutService;
use App\Services\AuditTrailService;
use App\Services\Student\StudentLifecycleService;
use App\Support\Financial\CurrencyEligibilityPolicy;
use App\Support\Financial\Exceptions\CurrencyNotUsableException;
use App\Support\Financial\FinancialOperation;
use App\Wallet\Contracts\WalletRechargeServiceInterface;
use App\Wallet\Enums\WalletRechargeStatus;
use App\Wallet\Enums\WalletStatus;
use App\Wallet\Exceptions\WalletException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Wallet recharge (SRS §13.11/§13.12) — the DOMAIN half only.
 *
 * This service decides WHETHER a student may add money and HOW MUCH.
 * It does not know what a gateway is. It holds no provider client, no
 * provider key, no secret, and contains no provider name in any
 * conditional. Creating the external order, storing provider identity
 * and settling the charge all belong to the generic payment kernel
 * (PaymentCheckoutService / PaymentService / Payment), exactly as they
 * do for package purchase and booking payment.
 *
 * It previously drove RazorpayGatewayClient and StripeGatewayClient
 * directly and stored provider ids on `wallet_recharges`, which meant
 * one external charge was described by two independent records that
 * could disagree about whether money had arrived.
 *
 * Responsibility split:
 *     THIS SERVICE            who may recharge, for how much, in which currency
 *     PaymentCheckoutService  attempt <-> provider-order orchestration
 *     PaymentService          attempt record mechanics
 *     WalletRechargeSettlement recharge lifecycle + wallet credit
 *     WalletLedgerService     the only writer of a wallet balance
 *
 * Nothing here ever credits a wallet. initiate() only creates a
 * Requested recharge and an open Payment attempt; a wallet moves only
 * when an authenticated provider event reaches the settlement service.
 */
final class WalletRechargeService implements WalletRechargeServiceInterface
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly CurrencyEligibilityPolicy $currencyEligibility,
        private readonly StudentLifecycleService $studentLifecycle,
        private readonly PaymentCollectionEligibilityServiceInterface $collectionEligibility,
        private readonly PaymentCheckoutService $checkout,
        private readonly AuditTrailService $audit,
        private readonly CountryFeatureResolver $countryFeatures,
        private readonly CountryResolver $countryResolver,
    ) {}

    /**
     * Every eligibility question is answered BEFORE any money state
     * exists: a refused recharge creates no WalletRecharge, no Payment,
     * and reaches no provider.
     */
    public function initiate(User $student, int $amountMinor): PaymentCheckoutData
    {
        $this->studentLifecycle->assertEligibleForStudentAction($student);

        $country = $this->countryResolver->forStudent($student);

        if (! $this->countryFeatures->isEnabled(CountryFeature::WalletRecharge, $country)) {
            throw new WalletException('Wallet recharge is not currently enabled.');
        }

        if ($amountMinor <= 0) {
            throw new WalletException('Enter a valid recharge amount.');
        }

        $wallet = $this->resolveWalletForRecharge($student);

        $this->assertAmountWithinLimits($amountMinor, $wallet->currency_code);

        try {
            $this->currencyEligibility->assertUsable($wallet->currency_code, FinancialOperation::NewInitiation, lock: true);
        } catch (CurrencyNotUsableException) {
            throw new WalletException('Your wallet currency is not currently active.');
        }

        $provider = $this->resolveRechargeCapableProvider($student, $wallet->currency_code);

        $recharge = $this->openRecharge($student, $wallet, $amountMinor);

        try {
            // The provider key is passed in, never chosen here, and the
            // amount/currency come from the recharge's own snapshot —
            // never from the request, and never re-resolved from the
            // market after the domain record exists.
            return $this->checkout->start($recharge, $provider);
        } catch (PaymentException $e) {
            // PaymentCheckoutService already closed its own attempt as
            // Failed. The recharge follows, so a student is not left
            // with an open request that can never be paid; a retry
            // creates a fresh recharge.
            $recharge->fill([
                'status' => WalletRechargeStatus::Failed,
                'failure_code' => 'provider_order_failed',
                'failure_reason' => $e->getMessage(),
                'failed_at' => now(),
            ])->save();

            throw new WalletException($e->getMessage(), previous: $e);
        }
    }

    /**
     * Re-presents the open attempt for a recharge the student has
     * already started, rather than opening a second one. Ownership is
     * derived from the recharge, never from the request.
     *
     * @throws WalletException when the recharge is not this student's, or has nothing open
     */
    public function resumeCheckout(User $student, WalletRecharge $recharge): PaymentCheckoutData
    {
        if ((int) $recharge->user_id !== (int) $student->id) {
            throw new WalletException('This recharge does not belong to you.');
        }

        $open = $this->checkout->openAttemptFor($recharge);

        if ($open === null) {
            throw new WalletException('This recharge has no payment in progress.');
        }

        try {
            return $this->checkout->resume($open, $recharge);
        } catch (PaymentException $e) {
            throw new WalletException($e->getMessage(), previous: $e);
        }
    }

    /**
     * The wallet-domain record of an intent to add money. Deliberately
     * carries no provider field of any kind — see the model docblock.
     *
     * The reference is generated server-side and is the value the
     * student, an operator, and the provider's order notes all use to
     * name this recharge.
     */
    private function openRecharge(User $student, Wallet $wallet, int $amountMinor): WalletRecharge
    {
        $reference = 'WRCH-'.strtoupper(Str::random(12));

        $recharge = DB::transaction(fn (): WalletRecharge => WalletRecharge::query()->create([
            'wallet_id' => $wallet->id,
            'user_id' => $student->id,
            'amount_minor' => $amountMinor,
            'currency_code' => $wallet->currency_code,
            'status' => WalletRechargeStatus::Requested,
            'reference' => $reference,
            'created_by' => $student->id,
        ]));

        $this->audit->logUser(
            $student,
            'wallet_recharges',
            'initiated',
            sprintf('Wallet recharge %s initiated.', $reference),
            $recharge,
            ['amount_minor' => $amountMinor, 'currency_code' => $wallet->currency_code],
        );

        return $recharge;
    }

    /**
     * Wallet recharge is external money collection and therefore obeys
     * exactly the same market gate as booking collection — one source of
     * truth, asked the same question with a different transaction type.
     *
     * PaymentCollectionEligibilityService covers payments_enabled, the
     * collection rollout scope, Country.status === 'active' (the
     * canonical market gate), provider routing and configuration, the
     * provider's approved billing currencies — which for Razorpay is
     * where the international attestation
     * (razorpay_international_enabled + razorpay_international_currencies)
     * is enforced, so a non-INR recharge is gated identically to a
     * non-INR booking and NZD/SAR stay blocked until an operator
     * confirms them — and provider health.
     *
     * The `wallet_recharge` transaction type carries the remaining
     * wallet-specific question, so no wallet-only currency or country
     * allowlist exists anywhere.
     *
     * @return non-empty-string the resolved provider's key
     */
    private function resolveRechargeCapableProvider(User $student, string $walletCurrency): string
    {
        $eligibility = $this->collectionEligibility->resolve(
            $student->profile?->country?->iso2,
            $walletCurrency,
            WalletRechargeServiceInterface::TRANSACTION_TYPE,
        );

        if (! $eligibility->isEligible || $eligibility->provider === null) {
            throw new WalletException($eligibility->summary() !== ''
                ? $eligibility->summary()
                : 'Wallet recharge is not currently available for your account.');
        }

        return $eligibility->provider;
    }

    private function resolveWalletForRecharge(User $student): Wallet
    {
        try {
            $wallet = $this->wallets->getOrCreateWallet($student, null, $student);
        } catch (CurrencyNotUsableException|ValidationException) {
            throw new WalletException('Your wallet currency is not currently active.');
        }

        if ($wallet->status === WalletStatus::Frozen) {
            throw new WalletException('Your wallet is frozen and cannot accept a new recharge right now.');
        }

        if ($wallet->status === WalletStatus::Closed) {
            throw new WalletException('Your wallet is closed and cannot be recharged.');
        }

        return $wallet;
    }

    /**
     * SRS §13.12. Limits come from the wallet's OWN currency row, in
     * that currency's own minor units — never from a platform-wide
     * scalar reinterpreted per currency, which is what previously turned
     * one configured "100" into a ₹100 floor in India and a $100 floor
     * in the United States.
     *
     * An unconfigured limit is NULL and simply does not constrain;
     * `amount > 0` (checked by the caller, and by the
     * chk_wallet_recharges_amount_positive constraint) is the only
     * universal floor. No default figure is substituted here.
     */
    private function assertAmountWithinLimits(int $amountMinor, string $currencyCode): void
    {
        app(WalletRechargeAmountPolicy::class)->assert($amountMinor, $currencyCode);
    }
}
