<?php

declare(strict_types=1);

namespace App\Wallet\Services;

use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Services\PaymentProviderResolver;
use App\Country\Enums\CountryFeature;
use App\Country\Services\CountryFeatureResolver;
use App\Country\Services\CountryResolver;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletRecharge;
use App\Services\AuditTrailService;
use App\Services\Payment\PaymentWebhookSignatureService;
use App\Services\Student\StudentLifecycleService;
use App\Settings\PaymentGatewaySettings;
use App\Settings\WalletSettings;
use App\Support\Financial\CurrencyEligibilityPolicy;
use App\Support\Financial\Exceptions\CurrencyNotUsableException;
use App\Support\Financial\FinancialOperation;
use App\Support\MoneyFormatter;
use App\Wallet\Contracts\WalletRechargeServiceInterface;
use App\Wallet\DTOs\WalletRechargeCheckoutData;
use App\Wallet\DTOs\WalletRechargeProviderEvent;
use App\Wallet\DTOs\WalletRechargeSettlementResult;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Enums\WalletRechargeProviderEventType;
use App\Wallet\Enums\WalletRechargeStatus;
use App\Wallet\Enums\WalletStatus;
use App\Wallet\Events\WalletRechargeCreditFailed;
use App\Wallet\Events\WalletRechargeFailed;
use App\Wallet\Events\WalletRechargeSucceeded;
use App\Wallet\Exceptions\WalletException;
use App\Wallet\Exceptions\WalletNotUsableException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Wallet recharge via a payment gateway (SRS §13.11/§13.12). Not
 * PaymentProviderInterface — that interface is typed to Booking
 * throughout. This orchestrates the same low-level gateway SDK
 * clients (RazorpayGatewayClient) PaymentProviderInterface
 * implementations use internally, and reuses PaymentProviderResolver
 * only for configured-provider selection.
 *
 * The wallet is never credited before a provider has authoritatively
 * confirmed capture: initiate() only ever creates a Pending/
 * ProviderCreated row, never a ledger entry. Only processProviderEvent()
 * (reached from a verified browser callback or a verified webhook)
 * ever calls WalletLedgerService::credit().
 */
final class WalletRechargeService implements WalletRechargeServiceInterface
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly WalletLedgerService $walletLedger,
        private readonly CurrencyEligibilityPolicy $currencyEligibility,
        private readonly StudentLifecycleService $studentLifecycle,
        private readonly PaymentProviderResolver $providers,
        private readonly PaymentGatewaySettings $gatewaySettings,
        private readonly WalletSettings $walletSettings,
        private readonly RazorpayGatewayClient $razorpay,
        private readonly StripeGatewayClient $stripe,
        private readonly AuditTrailService $audit,
        private readonly CountryFeatureResolver $countryFeatures,
        private readonly CountryResolver $countryResolver,
    ) {}

    public function initiate(User $student, int $amountMinor): WalletRechargeCheckoutData
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

        $reference = 'WRCH-'.strtoupper(Str::random(12));

        $recharge = WalletRecharge::query()->create([
            'wallet_id' => $wallet->id,
            'user_id' => $student->id,
            'provider' => $provider,
            'amount_minor' => $amountMinor,
            'currency_code' => $wallet->currency_code,
            'status' => WalletRechargeStatus::Pending,
            'idempotency_key' => $reference,
            'created_by' => $student->id,
        ]);

        $this->audit->logUser(
            $student,
            'wallet_recharges',
            'initiated',
            sprintf('Wallet recharge %s initiated via %s.', $reference, $provider),
            $recharge,
            ['amount_minor' => $amountMinor, 'currency_code' => $wallet->currency_code, 'provider' => $provider],
        );

        try {
            $checkoutPayload = $this->createProviderOrder($provider, $recharge);
        } catch (WalletException $e) {
            $recharge->forceFill([
                'status' => WalletRechargeStatus::Failed,
                'failure_code' => 'provider_order_failed',
                'failure_reason' => $e->getMessage(),
                'failed_at' => now(),
            ])->save();

            throw $e;
        }

        $recharge->forceFill(['status' => WalletRechargeStatus::ProviderCreated])->save();

        return new WalletRechargeCheckoutData(
            rechargeId: $recharge->id,
            provider: $provider,
            reference: $reference,
            amountMinor: $amountMinor,
            currencyCode: $wallet->currency_code,
            checkoutPayload: $checkoutPayload,
        );
    }

    public function verifyRazorpayCheckout(User $student, string $orderId, string $paymentId, string $signature): WalletRecharge
    {
        $keySecret = (string) PaymentWebhookSignatureService::decryptSecret($this->gatewaySettings, 'razorpay_key_secret');

        $expected = hash_hmac('sha256', "{$orderId}|{$paymentId}", $keySecret);

        if (! hash_equals($expected, $signature)) {
            throw new WalletException('Payment verification failed.');
        }

        $recharge = WalletRecharge::query()
            ->where('provider', 'razorpay')
            ->where('provider_order_id', $orderId)
            ->first();

        if ($recharge === null || (int) $recharge->user_id !== (int) $student->id) {
            throw new WalletException('This payment does not belong to your recharge.');
        }

        $result = $this->processProviderEvent(new WalletRechargeProviderEvent(
            provider: 'razorpay',
            reference: $recharge->idempotency_key,
            providerOrderId: $orderId,
            providerPaymentId: $paymentId,
            amountMinor: $recharge->amount_minor,
            currencyCode: $recharge->currency_code,
            type: WalletRechargeProviderEventType::Captured,
        ));

        return $result->recharge;
    }

    public function processProviderEvent(WalletRechargeProviderEvent $event): WalletRechargeSettlementResult
    {
        if ($event->type === WalletRechargeProviderEventType::Failed) {
            $recharge = $this->markProviderFailure($event->provider, $event->reference, 'provider_reported_failure', $event->reason);

            return new WalletRechargeSettlementResult($recharge, credited: false, ignored: false, reason: $event->reason);
        }

        if ($event->type !== WalletRechargeProviderEventType::Captured) {
            $recharge = WalletRecharge::query()->where('idempotency_key', $event->reference)->first();

            if ($recharge === null) {
                throw new WalletException('Unknown recharge reference.');
            }

            return new WalletRechargeSettlementResult($recharge, credited: false, ignored: true, reason: 'Event is not yet actionable.');
        }

        [$recharge, $credited, $creditFailed, $ignored, $reason] = DB::transaction(function () use ($event): array {
            $locked = WalletRecharge::query()->where('idempotency_key', $event->reference)->lockForUpdate()->first();

            if ($locked === null) {
                throw new WalletException('Unknown recharge reference.');
            }

            if ($locked->status === WalletRechargeStatus::Succeeded) {
                return [$locked, false, false, true, 'Already settled.'];
            }

            if ($locked->status->isTerminal()) {
                return [$locked, false, false, true, sprintf('Recharge is %s and cannot be settled.', $locked->status->label())];
            }

            if (! $locked->status->isAwaitingSettlement() && ! $locked->status->needsCreditRetry()) {
                return [$locked, false, false, true, sprintf('Recharge is %s and is not awaiting settlement.', $locked->status->label())];
            }

            if (strtolower($locked->provider) !== strtolower($event->provider)) {
                throw new WalletException('Recharge provider does not match the confirmed event.');
            }

            if ($locked->amount_minor !== $event->amountMinor) {
                throw new WalletException('Recharge amount does not match the confirmed provider amount.');
            }

            if (strtoupper($locked->currency_code) !== strtoupper($event->currencyCode)) {
                throw new WalletException('Recharge currency does not match the confirmed provider currency.');
            }

            if (
                $locked->provider_order_id !== null
                && $event->providerOrderId !== null
                && $locked->provider_order_id !== $event->providerOrderId
            ) {
                throw new WalletException('Recharge provider reference does not match the confirmed event.');
            }

            $locked->forceFill([
                'provider_order_id' => $locked->provider_order_id ?? $event->providerOrderId,
                'provider_payment_id' => $locked->provider_payment_id ?? $event->providerPaymentId,
                'provider_confirmed_at' => $locked->provider_confirmed_at ?? now(),
                'status' => WalletRechargeStatus::CreditPending,
            ])->save();

            $settled = $this->applyWalletCredit($locked->refresh());

            return [
                $settled,
                $settled->status === WalletRechargeStatus::Succeeded,
                $settled->status === WalletRechargeStatus::CreditFailed,
                false,
                null,
            ];
        });

        if ($credited) {
            WalletRechargeSucceeded::dispatch($recharge);
        } elseif ($creditFailed) {
            WalletRechargeCreditFailed::dispatch($recharge);
        }

        return new WalletRechargeSettlementResult($recharge, $credited, $ignored, $reason);
    }

    public function retryPendingCredit(WalletRecharge $recharge): WalletRecharge
    {
        if (! $recharge->status->needsCreditRetry()) {
            throw new WalletException(sprintf('Recharge %s is %s and does not need a credit retry.', $recharge->idempotency_key, $recharge->status->label()));
        }

        $result = DB::transaction(function () use ($recharge): WalletRecharge {
            $locked = WalletRecharge::query()->whereKey($recharge->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === WalletRechargeStatus::Succeeded) {
                return $locked;
            }

            if (! $locked->status->needsCreditRetry()) {
                throw new WalletException(sprintf('Recharge %s is %s and does not need a credit retry.', $locked->idempotency_key, $locked->status->label()));
            }

            return $this->applyWalletCredit($locked);
        });

        if ($result->status === WalletRechargeStatus::Succeeded) {
            WalletRechargeSucceeded::dispatch($result);
        } elseif ($result->status === WalletRechargeStatus::CreditFailed) {
            WalletRechargeCreditFailed::dispatch($result);
        }

        return $result;
    }

    public function markProviderFailure(string $provider, string $reference, string $failureCode, ?string $reason = null): WalletRecharge
    {
        $recharge = DB::transaction(function () use ($reference, $failureCode, $reason): WalletRecharge {
            $locked = WalletRecharge::query()->where('idempotency_key', $reference)->lockForUpdate()->first();

            if ($locked === null) {
                throw new WalletException('Unknown recharge reference.');
            }

            // A recharge already terminal, or one whose provider capture
            // is already confirmed (CreditPending/CreditFailed), must
            // never be downgraded into an ordinary failure — that money
            // is real and belongs on the credit-retry path, never here.
            if ($locked->status->isTerminal() || $locked->status->needsCreditRetry()) {
                return $locked;
            }

            $locked->forceFill([
                'status' => WalletRechargeStatus::Failed,
                'failure_code' => $failureCode,
                'failure_reason' => $reason,
                'failed_at' => now(),
            ])->save();

            $this->audit->logSystem(
                'wallet_recharges',
                'failed',
                sprintf('Wallet recharge %s failed (%s).', $locked->idempotency_key, $failureCode),
                $locked,
                ['failure_code' => $failureCode],
            );

            return $locked;
        });

        if ($recharge->status === WalletRechargeStatus::Failed && $recharge->failure_code === $failureCode) {
            WalletRechargeFailed::dispatch($recharge);
        }

        return $recharge;
    }

    /**
     * The atomic credit step — caller must already hold the locked
     * WalletRecharge row and have moved it to CreditPending. Never
     * throws for an ordinary "wallet can't be credited" outcome; it
     * records CreditFailed instead so the caller's transaction still
     * commits with the recharge in a durable, retryable state.
     */
    private function applyWalletCredit(WalletRecharge $recharge): WalletRecharge
    {
        $wallet = Wallet::query()->whereKey($recharge->wallet_id)->lockForUpdate()->first();

        if (
            $wallet === null
            || (int) $wallet->user_id !== (int) $recharge->user_id
            || strtoupper($wallet->currency_code) !== strtoupper($recharge->currency_code)
        ) {
            return $this->markCreditFailed($recharge, 'wallet_invalid', 'The destination wallet is no longer valid for this recharge.');
        }

        try {
            $this->walletLedger->credit(
                $wallet,
                $recharge->amount_minor,
                WalletLedgerEntryType::RechargeConfirmed,
                $recharge->user,
                idempotencyKey: $this->creditIdempotencyKey($recharge),
                description: sprintf('Wallet recharge %s.', $recharge->idempotency_key),
                sourceType: WalletRecharge::class,
                sourceId: (string) $recharge->id,
            );
        } catch (WalletNotUsableException $e) {
            return $this->markCreditFailed($recharge, 'wallet_not_usable', $e->getMessage());
        }

        $recharge->forceFill([
            'status' => WalletRechargeStatus::Succeeded,
            'succeeded_at' => now(),
        ])->save();

        $this->audit->logSystem(
            'wallet_recharges',
            'succeeded',
            sprintf('Wallet recharge %s succeeded.', $recharge->idempotency_key),
            $recharge,
            ['amount_minor' => $recharge->amount_minor, 'currency_code' => $recharge->currency_code],
        );

        return $recharge;
    }

    private function markCreditFailed(WalletRecharge $recharge, string $code, string $reason): WalletRecharge
    {
        $recharge->forceFill([
            'status' => WalletRechargeStatus::CreditFailed,
            'failure_code' => $code,
            'failure_reason' => $reason,
        ])->save();

        $this->audit->logSystem(
            'wallet_recharges',
            'credit_failed',
            sprintf('Wallet recharge %s was captured by the provider but could not be credited.', $recharge->idempotency_key),
            $recharge,
            ['failure_code' => $code, 'amount_minor' => $recharge->amount_minor],
        );

        return $recharge;
    }

    private function creditIdempotencyKey(WalletRecharge $recharge): string
    {
        return sprintf('wallet-recharge:%s', $recharge->id);
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

    private function assertAmountWithinLimits(int $amountMinor, string $currencyCode): void
    {
        $minorUnits = MoneyFormatter::minorUnitsFor($currencyCode);
        $minMinor = MoneyFormatter::toMinor((string) $this->walletSettings->minimum_recharge_amount, $minorUnits);
        $maxMinor = MoneyFormatter::toMinor((string) $this->walletSettings->maximum_recharge_amount, $minorUnits);

        if ($amountMinor < $minMinor) {
            throw new WalletException(sprintf('The minimum recharge amount is %s.', MoneyFormatter::format($minMinor, $currencyCode)));
        }

        if ($maxMinor > 0 && $amountMinor > $maxMinor) {
            throw new WalletException(sprintf('The maximum recharge amount is %s.', MoneyFormatter::format($maxMinor, $currencyCode)));
        }
    }

    /** @return non-empty-string the resolved provider's key */
    private function resolveRechargeCapableProvider(User $student, string $walletCurrency): string
    {
        $countryIso2 = $student->profile?->country?->iso2;

        try {
            $provider = $this->providers->current($countryIso2);
        } catch (BookingException $e) {
            throw new WalletException($e->getMessage());
        }

        $capabilities = $provider->capabilities();

        if (! $capabilities->supportsWalletRecharge) {
            throw new WalletException('Wallet recharge is not currently available through your configured payment provider.');
        }

        $supported = array_map(strtoupper(...), $provider->supportedCurrencies());

        if (! in_array(strtoupper($walletCurrency), $supported, true)) {
            throw new WalletException('No payment method is available for your wallet currency.');
        }

        return $provider->key();
    }

    /** @return array<string, mixed> gateway-neutral frontend checkout payload */
    private function createProviderOrder(string $providerKey, WalletRecharge $recharge): array
    {
        return match ($providerKey) {
            'razorpay' => $this->createRazorpayOrder($recharge),
            'stripe' => $this->createStripeOrder($recharge),
            'fake' => $this->createFakeOrder($recharge),
            default => throw new WalletException(sprintf('Wallet recharge is not available through "%s" yet.', $providerKey)),
        };
    }

    /**
     * client_secret is single-use and frontend-facing only — returned
     * once, directly, in the checkout payload below. It is never
     * written to wallet_recharges (no column exists for it), never
     * logged, and never placed in audit metadata — mirrors
     * StripePaymentProvider's own documented rule for booking payments.
     *
     * @return array<string, mixed>
     */
    private function createStripeOrder(WalletRecharge $recharge): array
    {
        if (! $this->gatewaySettings->stripe_enabled || blank($this->gatewaySettings->stripe_publishable_key) || blank($this->gatewaySettings->stripe_secret_key)) {
            throw new WalletException('Stripe is not configured.');
        }

        $secretKey = (string) PaymentWebhookSignatureService::decryptSecret($this->gatewaySettings, 'stripe_secret_key');

        try {
            // Stripe's own idempotency-key support, exactly mirroring
            // StripePaymentProvider::createPayment() — deduplicated
            // Stripe-side too, not just at our own unique-constraint layer.
            $intent = $this->stripe->createPaymentIntent($secretKey, [
                'amount' => $recharge->amount_minor,
                'currency' => strtolower($recharge->currency_code),
                'metadata' => [
                    'wallet_recharge_reference' => $recharge->idempotency_key,
                ],
            ], $recharge->idempotency_key);
        } catch (GatewayRequestException $e) {
            throw new WalletException('Stripe payment intent creation failed: '.$e->getMessage());
        }

        $intentId = (string) ($intent['id'] ?? '');

        if ($intentId === '') {
            throw new WalletException('Stripe did not return a payment intent id.');
        }

        $recharge->forceFill(['provider_order_id' => $intentId])->save();

        return [
            'provider' => 'stripe',
            'payment_intent_id' => $intentId,
            'client_secret' => (string) ($intent['client_secret'] ?? ''),
            'publishable_key' => (string) $this->gatewaySettings->stripe_publishable_key,
            'amount_minor' => $recharge->amount_minor,
            'currency' => $recharge->currency_code,
        ];
    }

    /** @return array<string, mixed> */
    private function createRazorpayOrder(WalletRecharge $recharge): array
    {
        if (! $this->gatewaySettings->razorpay_enabled || blank($this->gatewaySettings->razorpay_key_id) || blank($this->gatewaySettings->razorpay_key_secret)) {
            throw new WalletException('Razorpay is not configured.');
        }

        $keyId = (string) $this->gatewaySettings->razorpay_key_id;
        $keySecret = (string) PaymentWebhookSignatureService::decryptSecret($this->gatewaySettings, 'razorpay_key_secret');

        try {
            $order = $this->razorpay->createOrder($keyId, $keySecret, [
                'amount' => $recharge->amount_minor,
                'currency' => $recharge->currency_code,
                'receipt' => $recharge->idempotency_key,
                'notes' => [
                    'wallet_recharge_reference' => $recharge->idempotency_key,
                ],
            ]);
        } catch (GatewayRequestException $e) {
            throw new WalletException('Razorpay order creation failed: '.$e->getMessage());
        }

        $orderId = (string) ($order['id'] ?? '');

        if ($orderId === '') {
            throw new WalletException('Razorpay did not return an order id.');
        }

        $recharge->forceFill(['provider_order_id' => $orderId])->save();

        return [
            'provider' => 'razorpay',
            'order_id' => $orderId,
            'key_id' => $keyId,
            'amount_minor' => $recharge->amount_minor,
            'currency' => $recharge->currency_code,
        ];
    }

    /** Test/local convenience only — reached only when the resolver has already gated fake to local/testing. */
    private function createFakeOrder(WalletRecharge $recharge): array
    {
        $orderId = 'fake_recharge_order_'.$recharge->id;
        $recharge->forceFill(['provider_order_id' => $orderId])->save();

        return [
            'provider' => 'fake',
            'order_id' => $orderId,
            'amount_minor' => $recharge->amount_minor,
            'currency' => $recharge->currency_code,
        ];
    }
}
