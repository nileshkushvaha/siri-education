<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Booking\Exceptions\BookingException;
use App\Booking\Services\PaymentProviderResolver;
use App\Livewire\Frontend\Auth\Concerns\ThrottlesLivewireRequests;
use App\Models\Wallet;
use App\Models\WalletRecharge;
use App\Settings\FeatureSettings;
use App\Settings\GeneralSettings;
use App\Settings\WalletSettings;
use App\Support\MoneyFormatter;
use App\Wallet\Contracts\WalletRechargeServiceInterface;
use App\Wallet\DTOs\WalletRechargeProviderEvent;
use App\Wallet\Enums\WalletRechargeProviderEventType;
use App\Wallet\Enums\WalletRechargeStatus;
use App\Wallet\Exceptions\WalletException;
use App\Wallet\Services\WalletLedgerService;
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
        } catch (WalletException $e) {
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
            $this->pendingStripeRechargeId = $checkout->rechargeId;

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

    /** Razorpay Checkout.js success callback — a fast path, never trusted alone; verifyRazorpayCheckout() re-verifies server-side before anything is shown as settled. */
    public function verifyWalletRecharge(string $orderId, string $paymentId, string $signature): void
    {
        $this->rechargeBanner = '';

        try {
            $recharge = $this->recharges->verifyRazorpayCheckout(auth()->user(), $orderId, $paymentId, $signature);
        } catch (WalletException $e) {
            $this->rechargeBanner = $e->getMessage();

            return;
        }

        $this->applySettlementBanner($recharge);
    }

    /**
     * Stripe has no browser-side settlement step — stripe.confirmPayment()
     * completing only means checkout progressed, never that the wallet
     * was credited. This re-reads the server's own record, scoped to
     * the authenticated student's own recharge, and never settles
     * anything itself (mirrors BookingHistory::checkPaymentStatus()'s
     * identical read-only rationale for Stripe booking payments).
     */
    public function pollWalletRechargeStatus(): void
    {
        if ($this->pendingStripeRechargeId === null) {
            return;
        }

        $recharge = WalletRecharge::query()
            ->whereKey($this->pendingStripeRechargeId)
            ->where('user_id', auth()->id())
            ->first();

        if ($recharge === null) {
            return;
        }

        if (! $recharge->status->isTerminal() && ! $recharge->status->needsCreditRetry()) {
            $this->rechargeBanner = 'We could not confirm your payment yet. Please check your wallet again shortly.';

            return;
        }

        $this->applySettlementBanner($recharge);

        if ($recharge->status->isTerminal()) {
            $this->pendingStripeRechargeId = null;
        }
    }

    /**
     * Local/testing-only convenience: the fake provider has no real
     * checkout UI to complete. Mirrors BookingWizard::simulateFakePayment()'s
     * identical rationale and environment guard.
     */
    public function simulateFakeRecharge(bool $success): void
    {
        if (! app()->environment(['local', 'testing']) || $this->pendingFakeRecharge === []) {
            return;
        }

        $this->rechargeBanner = '';
        $reference = (string) $this->pendingFakeRecharge['reference'];
        $this->pendingFakeRecharge = [];

        $recharge = WalletRecharge::query()->where('idempotency_key', $reference)->first();

        if ($recharge === null) {
            return;
        }

        try {
            if ($success) {
                $result = $this->recharges->processProviderEvent(new WalletRechargeProviderEvent(
                    provider: 'fake',
                    reference: $reference,
                    providerOrderId: $recharge->provider_order_id,
                    providerPaymentId: 'fake_payment_'.$recharge->id,
                    amountMinor: $recharge->amount_minor,
                    currencyCode: $recharge->currency_code,
                    type: WalletRechargeProviderEventType::Captured,
                ));
                $this->applySettlementBanner($result->recharge);
            } else {
                $failed = $this->recharges->markProviderFailure('fake', $reference, 'simulated_failure', 'Simulated failure (fake provider).');
                $this->applySettlementBanner($failed);
            }
        } catch (WalletException $e) {
            $this->rechargeBanner = $e->getMessage();
        }
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
        ]);
    }

    private function applySettlementBanner(WalletRecharge $recharge): void
    {
        $this->rechargeBanner = match ($recharge->status) {
            WalletRechargeStatus::Succeeded => '',
            WalletRechargeStatus::CreditFailed => 'Your payment was received. We are completing your wallet credit — this can take a few minutes.',
            WalletRechargeStatus::Failed => 'Your recharge could not be completed. Please try again.',
            default => 'We could not confirm your payment yet. Please check your wallet again shortly.',
        };

        if ($recharge->status === WalletRechargeStatus::Succeeded) {
            $this->rechargeAmount = '';
        }
    }

    /** Read-only preview — never creates a wallet, order, or recharge attempt merely from viewing this screen. */
    private function rechargeAvailable(string $currencyCode): bool
    {
        if (! app(FeatureSettings::class)->wallet_enabled) {
            return false;
        }

        $countryIso2 = auth()->user()?->profile?->country?->iso2;

        try {
            $provider = app(PaymentProviderResolver::class)->current($countryIso2);
        } catch (BookingException) {
            return false;
        }

        $supported = array_map(strtoupper(...), $provider->supportedCurrencies());

        return $provider->capabilities()->supportsWalletRecharge
            && in_array(strtoupper($currencyCode), $supported, true);
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

    /** @return array{min: string, max: string} */
    private function rechargeLimits(string $currencyCode): array
    {
        $settings = app(WalletSettings::class);
        $minorUnits = MoneyFormatter::minorUnitsFor($currencyCode);

        return [
            'min' => MoneyFormatter::format(MoneyFormatter::toMinor((string) $settings->minimum_recharge_amount, $minorUnits), $currencyCode),
            'max' => MoneyFormatter::format(MoneyFormatter::toMinor((string) $settings->maximum_recharge_amount, $minorUnits), $currencyCode),
        ];
    }

    private function emptyStatement(): LengthAwarePaginator
    {
        return new LengthAwarePaginatorImpl([], 0, 10);
    }
}
