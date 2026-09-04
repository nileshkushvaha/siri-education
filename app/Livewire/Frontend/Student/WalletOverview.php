<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Booking\Contracts\PaymentCollectionEligibilityServiceInterface;
use App\Exceptions\Student\StudentActionNotAvailableException;
use App\Livewire\Frontend\Auth\Concerns\ThrottlesLivewireRequests;
use App\Models\Currency;
use App\Models\Payment;
use App\Models\Wallet;
use App\Models\WalletRecharge;
use App\Payments\DTOs\VerifiedPaymentEvent;
use App\Payments\Enums\PaymentEventType;
use App\Payments\Exceptions\PaymentException;
use App\Payments\Services\PaymentCallbackVerifier;
use App\Settings\FeatureSettings;
use App\Settings\GeneralSettings;
use App\Support\MoneyFormatter;
use App\Wallet\Contracts\WalletRechargeServiceInterface;
use App\Wallet\Enums\WalletRechargeStatus;
use App\Wallet\Exceptions\WalletException;
use App\Wallet\Services\WalletLedgerService;
use App\Wallet\Services\WalletRechargeReconciliationService;
use App\Wallet\Services\WalletRechargeSettlementService;
use App\Wallet\Support\WalletMoneyFormatter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator as LengthAwarePaginatorImpl;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only wallet display, plus the wallet-recharge entry point (SRS
 * §13.11). A wallet is created here only as a direct effect of a
 * student's own recharge attempt (WalletRechargeService::initiate()),
 * never merely by viewing this page.
 *
 * Single-currency assumption (documented, not a bug): this page shows
 * only the student's first wallet with no currency scoping/selector —
 * recharge always targets that same wallet's own currency, with no
 * student-facing currency or provider selector.
 */
final class WalletOverview extends Component
{
    use ThrottlesLivewireRequests;
    use WithPagination;

    public string $rechargeAmount = '';

    public string $rechargeBanner = '';

    /** Display-only — set after Stripe initiation so the browser knows which attempt to poll; never trusted as authoritative. */
    public ?string $pendingStripeRechargeId = null;

    /**
     * Razorpay attempt whose browser callback ran but whose capture the
     * provider had not yet confirmed. While set, the view polls
     * pollWalletRechargeStatus() so the balance appears without a reload.
     */
    public ?string $pendingRazorpayPaymentId = null;

    /** Polls made for the current pending attempt; caps how long the page keeps asking. */
    public int $rechargePollCount = 0;

    /** Poll ticks (3s apart) before we stop and hand over to the webhook/sweep. */
    public const int MAX_RECHARGE_POLLS = 40;

    /** Minimum gap between two provider status calls made on behalf of one student. */
    public const int PROVIDER_RECHECK_SECONDS = 5;

    /** @var array<string, mixed> */
    public array $pendingFakeRecharge = [];

    private WalletLedgerService $ledger;

    private WalletRechargeServiceInterface $recharges;

    public function boot(WalletLedgerService $ledger, WalletRechargeServiceInterface $recharges): void
    {
        $this->ledger = $ledger;
        $this->recharges = $recharges;
    }

    public function initiateRecharge(): void
    {
        $this->rechargeBanner = '';
        $this->pendingFakeRecharge = [];
        $this->pendingStripeRechargeId = null;

        try {
            $this->throttleLimiter('wallet-recharge-initiate', ['user_id' => auth()->id()], 'rechargeAmount');
        } catch (ValidationException $e) {
            $this->rechargeBanner = $e->getMessage();

            return;
        }

        $currencyCode = $this->currentCurrencyCode();

        try {
            $amountMinor = MoneyFormatter::toMinor($this->rechargeAmount, MoneyFormatter::minorUnitsFor($currencyCode));
        } catch (InvalidArgumentException $e) {
            $this->rechargeBanner = $e->getMessage();

            return;
        }

        try {
            $checkout = $this->recharges->initiate(auth()->user(), $amountMinor);
        } catch (WalletException|StudentActionNotAvailableException $e) {
            $this->rechargeBanner = $e->getMessage();

            return;
        }

        if ($checkout->provider === 'razorpay') {
            $this->dispatch(
                'wallet-recharge-checkout-ready',
                orderId: $checkout->checkoutPayload['order_id'],
                keyId: $checkout->checkoutPayload['key_id'],
                amountMinor: $checkout->amountMinor,
                currency: $checkout->currencyCode,
                name: auth()->user()?->name ?? '',
                email: auth()->user()?->email ?? '',
            );

            return;
        }

        if ($checkout->provider === 'stripe') {
            $this->pendingStripeRechargeId = $checkout->paymentId;

            $this->dispatch(
                'wallet-recharge-stripe-checkout-ready',
                clientSecret: $checkout->checkoutPayload['client_secret'],
                publishableKey: $checkout->checkoutPayload['publishable_key'],
            );

            return;
        }

        // Fake provider — local/testing only, no real checkout UI.
        $this->pendingFakeRecharge = ['reference' => $checkout->reference];
    }

    /**
     * Razorpay Checkout.js success callback.
     *
     * The browser is never the authority: PaymentCallbackVerifier proves
     * the signature and that the order belongs to THIS student's
     * recharge, and records the reported provider payment id. What
     * settles the money is the same server-to-server confirmation the
     * reconciliation sweep uses — run right now instead of ten minutes
     * from now. Razorpay is asked for the order; if it reports `paid`
     * with the expected amount and currency, WalletRechargeSettlementService
     * credits the wallet in this very request and the student sees the
     * new balance on return from checkout.
     *
     * If the provider has not caught up yet (capture still in flight),
     * nothing is credited, the "confirming" banner shows, and the view
     * starts polling — see pollWalletRechargeStatus(). The signed webhook
     * and the scheduled sweep remain the safety nets behind both.
     */
    public function verifyWalletRecharge(string $orderId, string $paymentId, string $signature): void
    {
        $this->rechargeBanner = '';

        $recharge = $this->openRecharge();

        if ($recharge === null) {
            return;
        }

        try {
            $payment = app(PaymentCallbackVerifier::class)->verifyRazorpayCheckout($recharge, $orderId, $paymentId, $signature);
        } catch (PaymentException $e) {
            $this->rechargeBanner = $e->getMessage();

            return;
        }

        $this->confirmWithProvider($payment);
        $this->trackPendingRecharge($payment, $recharge->refresh());
    }

    /**
     * Razorpay Checkout.js closed without a payment. Nothing was taken,
     * and nothing here can change money: it only stops the student from
     * wondering whether the closed window "did something".
     */
    public function razorpayCheckoutDismissed(): void
    {
        $recharge = $this->openRecharge();

        if ($recharge === null || $this->pendingRazorpayPaymentId !== null) {
            return;
        }

        $hasPaymentEvidence = $recharge->payments()->whereNotNull('provider_payment_id')->exists();

        if (! $hasPaymentEvidence) {
            $this->rechargeBanner = 'The payment window was closed before completing. No money was taken — you can try again whenever you like.';
        }
    }

    /**
     * Asks the provider, server to server, whether this attempt is paid
     * and settles it through the ONE settlement path if so. Idempotent
     * and safe to repeat: an attempt already settled by the webhook comes
     * back as a replay and credits nothing twice. Throttled per attempt so
     * a polling page never hammers the gateway.
     */
    private function confirmWithProvider(Payment $payment): void
    {
        $lastChecked = $payment->last_synced_at;

        if ($lastChecked !== null && $lastChecked->gt(now()->subSeconds(self::PROVIDER_RECHECK_SECONDS))) {
            return;
        }

        app(WalletRechargeReconciliationService::class)->reconcileOne($payment);
    }

    /** Applies the banner for the attempt's current state and arms or disarms polling. */
    private function trackPendingRecharge(Payment $payment, WalletRecharge $recharge): void
    {
        $this->applySettlementBanner($recharge);

        if ($recharge->status->isTerminal() || $recharge->status->needsCreditRetry()) {
            $this->pendingRazorpayPaymentId = null;
            $this->rechargePollCount = 0;

            return;
        }

        if ($this->pendingRazorpayPaymentId !== $payment->id) {
            $this->pendingRazorpayPaymentId = $payment->id;
            $this->rechargePollCount = 0;
        }
    }

    /** The student's own most recent recharge that is still awaiting payment. */
    private function openRecharge(): ?WalletRecharge
    {
        return WalletRecharge::query()
            ->where('user_id', auth()->id())
            ->where('status', WalletRechargeStatus::Requested)
            ->latest('created_at')
            ->first();
    }

    /**
     * Polled by the view (Razorpay: wire:poll; Stripe: the checkout script)
     * while a recharge the student just paid is not yet confirmed.
     *
     * Each tick re-reads the server's own record and, at most every few
     * seconds, asks the provider again through the same reconciliation
     * path the sweep uses — so a capture that lands two seconds after the
     * callback is credited two seconds after the callback, not on the
     * next scheduled sweep. Scoped to the authenticated student's own
     * attempt; nothing here trusts the browser about money.
     *
     * After MAX_RECHARGE_POLLS ticks the page stops asking and tells the
     * student plainly that the credit will arrive automatically, so they
     * neither refresh forever nor pay twice.
     */
    public function pollWalletRechargeStatus(): void
    {
        $paymentId = $this->pendingRazorpayPaymentId ?? $this->pendingStripeRechargeId;

        if ($paymentId === null) {
            return;
        }

        $payment = Payment::query()
            ->whereKey($paymentId)
            ->where('user_id', auth()->id())
            ->where('payable_type', WalletRecharge::PAYABLE_TYPE)
            ->first();

        if ($payment === null) {
            $this->clearPendingRecharge();

            return;
        }

        $recharge = WalletRecharge::query()
            ->whereKey($payment->payable_id)
            ->where('user_id', auth()->id())
            ->first();

        if ($recharge === null) {
            $this->clearPendingRecharge();

            return;
        }

        if (! $recharge->status->isTerminal() && ! $recharge->status->needsCreditRetry()) {
            $this->rechargePollCount++;

            if ($this->rechargePollCount > self::MAX_RECHARGE_POLLS) {
                $this->rechargeBanner = 'Your payment is taking longer than usual to confirm. If it went through, your wallet will be credited automatically and we will email you — there is no need to pay again.';
                $this->clearPendingRecharge();

                return;
            }

            $this->confirmWithProvider($payment);
            $recharge->refresh();
        }

        $this->applySettlementBanner($recharge);

        if ($recharge->status->isTerminal() || $recharge->status->needsCreditRetry()) {
            $this->clearPendingRecharge();
        } elseif ($this->pendingStripeRechargeId === null) {
            $this->rechargeBanner = 'We are confirming your payment with the gateway. Your balance will update here in a moment.';
        }
    }

    private function clearPendingRecharge(): void
    {
        $this->pendingRazorpayPaymentId = null;
        $this->pendingStripeRechargeId = null;
        $this->rechargePollCount = 0;
    }

    /**
     * Local/testing-only convenience: the fake provider has no real
     * checkout UI to complete. Mirrors BookingWizard::simulateFakePayment()'s
     * identical rationale and environment guard.
     *
     * Goes through the SAME WalletRechargeSettlementService a signed
     * webhook reaches, with the same VerifiedPaymentEvent shape — a
     * local shortcut that took a different settlement path would prove
     * nothing about the real one.
     */
    public function simulateFakeRecharge(bool $success): void
    {
        if (! app()->environment(['local', 'testing']) || $this->pendingFakeRecharge === []) {
            return;
        }

        $this->rechargeBanner = '';
        $reference = (string) $this->pendingFakeRecharge['reference'];
        $this->pendingFakeRecharge = [];

        $recharge = WalletRecharge::query()
            ->where('reference', $reference)
            ->where('user_id', auth()->id())
            ->first();

        if ($recharge === null) {
            return;
        }

        $payment = $recharge->payments()->first();

        if ($payment === null) {
            return;
        }

        $result = app(WalletRechargeSettlementService::class)->settle($payment, new VerifiedPaymentEvent(
            provider: (string) $payment->provider,
            type: $success ? PaymentEventType::Succeeded : PaymentEventType::Failed,
            reference: $payment->idempotency_key,
            providerOrderId: $payment->provider_order_id,
            providerPaymentId: 'fake_payment_'.$payment->id,
            amountMinor: (int) $payment->amount_minor,
            currencyCode: (string) $payment->currency_code,
            reason: $success ? null : 'Simulated failure (fake provider).',
        ));

        $this->applySettlementBanner($result->recharge?->refresh() ?? $recharge->refresh());
    }

    public function render(): View
    {
        $wallet = Wallet::query()->forUser(auth()->id())->first();
        $currencyCode = $wallet?->currency_code ?? $this->currentCurrencyCode();

        return view('livewire.frontend.student.wallet-overview', [
            'wallet' => $wallet,
            'entries' => $wallet ? $this->ledger->statement($wallet, 10) : $this->emptyStatement(),
            'rechargeAvailable' => $this->rechargeAvailable($currencyCode),
            'rechargeCurrencyCode' => $currencyCode,
            'rechargeLimits' => $this->rechargeLimits($currencyCode),
            'lowBalance' => $this->lowBalance($wallet),
        ]);
    }

    private function applySettlementBanner(WalletRecharge $recharge): void
    {
        $this->rechargeBanner = match ($recharge->status) {
            WalletRechargeStatus::Succeeded => sprintf(
                'Payment received — %s has been added to your wallet.',
                WalletMoneyFormatter::format(
                    (int) $recharge->amount_minor,
                    Currency::query()->where('code', $recharge->currency_code)->first(),
                    (string) $recharge->currency_code,
                ),
            ),
            WalletRechargeStatus::CreditFailed => 'Your payment was received. We are completing your wallet credit — this can take a few minutes.',
            WalletRechargeStatus::Failed => 'Your recharge could not be completed. Please try again.',
            // Includes AwaitingConfirmation, the state a Razorpay
            // browser callback now leaves a recharge in. The student's
            // payment may well have gone through; what has NOT happened
            // is the authoritative confirmation, so this deliberately
            // promises nothing about the balance.
            default => 'We are confirming your payment with the gateway. Your balance will update shortly.',
        };

        if ($recharge->status === WalletRechargeStatus::Succeeded) {
            $this->rechargeAmount = '';
        }
    }

    /**
     * Read-only preview — never creates a wallet, order, or recharge
     * attempt merely from viewing this screen.
     *
     * Asks PaymentCollectionEligibilityService the exact question
     * WalletRechargeService::initiate() will ask, rather than
     * re-deriving it. This screen previously reimplemented a narrower
     * gate (provider resolution + supportedCurrencies only), which could
     * disagree with the server in both directions: showing an Add Money
     * form that initiate() would refuse, or hiding one that was
     * genuinely available. The button state is a preview of the
     * authoritative answer, never a second opinion — and hiding it is
     * never the enforcement, which lives at the service boundary.
     */
    private function rechargeAvailable(string $currencyCode): bool
    {
        if (! app(FeatureSettings::class)->wallet_enabled) {
            return false;
        }

        return app(PaymentCollectionEligibilityServiceInterface::class)->resolve(
            auth()->user()?->profile?->country?->iso2,
            $currencyCode,
            WalletRechargeServiceInterface::TRANSACTION_TYPE,
        )->isEligible;
    }

    private function currentCurrencyCode(): string
    {
        $wallet = Wallet::query()->forUser(auth()->id())->first();

        if ($wallet !== null) {
            return $wallet->currency_code;
        }

        $user = auth()->user();

        return $user?->profile?->country?->defaultCurrency?->code ?? app(GeneralSettings::class)->default_currency;
    }

    /**
     * The limits WalletRechargeService will actually enforce, read from
     * the same per-currency source (SRS §13.12) rather than re-derived
     * from a platform-wide scalar. An unconfigured limit is null and is
     * simply not advertised — the view must not print a floor or ceiling
     * the server would not apply.
     *
     * @return array{min: ?string, max: ?string}
     */
    /**
     * Low-balance alert (Settings → Wallet, per currency). Null when the
     * student has no wallet, the currency has no threshold, or the
     * available balance is at or above it.
     *
     * @return array{threshold: string}|null
     */
    private function lowBalance(?Wallet $wallet): ?array
    {
        if ($wallet === null) {
            return null;
        }

        $threshold = $wallet->currency?->low_balance_threshold_minor;

        if ($threshold === null || $wallet->available_balance_minor >= $threshold) {
            return null;
        }

        return ['threshold' => WalletMoneyFormatter::format($threshold, $wallet->currency, $wallet->currency_code)];
    }

    private function rechargeLimits(string $currencyCode): array
    {
        $currency = Currency::query()
            ->where('code', strtoupper($currencyCode))
            ->first(['minimum_recharge_minor', 'maximum_recharge_minor', 'recharge_multiple_minor']);

        return [
            'min' => $currency?->minimum_recharge_minor === null
                ? null
                : MoneyFormatter::format($currency->minimum_recharge_minor, $currencyCode),
            'max' => $currency?->maximum_recharge_minor === null
                ? null
                : MoneyFormatter::format($currency->maximum_recharge_minor, $currencyCode),
            'multiple' => $currency?->recharge_multiple_minor === null
                ? null
                : MoneyFormatter::format($currency->recharge_multiple_minor, $currencyCode),
        ];
    }

    private function emptyStatement(): LengthAwarePaginator
    {
        return new LengthAwarePaginatorImpl([], 0, 10);
    }
}
