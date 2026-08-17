<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletRecharge;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Services\PaymentService;
use App\Wallet\Enums\WalletRechargeStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Wallet-domain state ONLY.
 *
 * This factory deliberately creates no provider identity: there are no
 * `provider`, `provider_order_id` or `provider_payment_id` columns on
 * `wallet_recharges` any more, and reintroducing those facts here would
 * quietly rebuild the duplicate-payment-identity architecture the
 * cutover removed. A test that needs a real external payment attaches
 * one with `withPayment()`, which creates a genuine `Payment` row —
 * the same record production uses.
 *
 * @extends Factory<WalletRecharge>
 */
class WalletRechargeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'wallet_id' => Wallet::factory(),
            'user_id' => User::factory(),
            'amount_minor' => 50000,
            'currency_code' => 'INR',
            'status' => WalletRechargeStatus::Requested,
            'reference' => 'WRCH-'.strtoupper(Str::random(12)),
            'metadata' => [],
        ];
    }

    /** The student asked to add money and the payment has not settled. */
    public function requested(): static
    {
        return $this->state(fn (): array => ['status' => WalletRechargeStatus::Requested]);
    }

    /** Captured by the provider; the wallet credit has not been applied yet. */
    public function creditPending(): static
    {
        return $this->state(fn (): array => ['status' => WalletRechargeStatus::CreditPending]);
    }

    /** Captured by the provider; the wallet credit was refused and must stay retryable. */
    public function creditFailed(): static
    {
        return $this->state(fn (): array => [
            'status' => WalletRechargeStatus::CreditFailed,
            'failure_code' => 'wallet_not_usable',
            'failure_reason' => 'The destination wallet is no longer valid for this recharge.',
        ]);
    }

    public function succeeded(): static
    {
        return $this->state(fn (): array => [
            'status' => WalletRechargeStatus::Succeeded,
            'succeeded_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => WalletRechargeStatus::Failed,
            'failed_at' => now(),
        ]);
    }

    /**
     * Attaches a real `Payment` attempt — the only place provider
     * identity for a recharge may come from.
     *
     * Built through PaymentService, exactly as production and the
     * package tests do, so a fixture can never describe an attempt the
     * real code could not have produced.
     */
    public function withPayment(PaymentStatus $status = PaymentStatus::Pending, string $provider = 'razorpay'): static
    {
        return $this->afterCreating(function (WalletRecharge $recharge) use ($status, $provider): void {
            $payments = app(PaymentService::class);

            $payment = $payments->startAttempt($recharge, $provider, $recharge->reference);
            $payments->recordProviderOrder($payment, $provider.'_order_'.Str::upper(Str::random(10)));

            if ($status !== PaymentStatus::Pending) {
                $payments->transition($payment->refresh(), $status, [
                    'provider_payment_id' => $status === PaymentStatus::Paid
                        ? $provider.'_pay_'.Str::upper(Str::random(10))
                        : null,
                ]);
            }
        });
    }
}
