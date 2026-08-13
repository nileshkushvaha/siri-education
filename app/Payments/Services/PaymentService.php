<?php

declare(strict_types=1);

namespace App\Payments\Services;

use App\Models\Payment;
use App\Payments\Contracts\Payable;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Exceptions\PaymentException;
use App\Services\AuditTrailService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4B.1 — the sole writer of generic Payment attempts.
 *
 * SCOPE: this is a foundation, not a checkout. There is deliberately no
 * gateway call, no provider resolution, and no webhook handling here
 * yet — Phase 4B.2 adds those once StudentPackagePurchase exists as a
 * real consumer. Only the methods needed to prove the record/contract
 * architecture are implemented; speculative methods were intentionally
 * left out rather than stubbed.
 *
 * Because `payments.payable_type`/`payable_id` cannot carry a database
 * foreign key (they span domains), payable existence and ownership are
 * this service's responsibility — that is the trade documented on the
 * migration.
 */
final class PaymentService
{
    private const string LOG_NAME = 'payments';

    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * Opens a new attempt against a payable.
     *
     * Refuses while another attempt is still open, so a double-submit
     * cannot create two live gateway orders for the same payable. A
     * retry after a Failed/Cancelled attempt is allowed and creates a
     * NEW row — settled attempts are never mutated or reused.
     *
     * @throws PaymentException when an attempt is already open, or the amount is not collectable
     */
    public function startAttempt(Payable $payable, string $provider, ?string $idempotencyKey = null): Payment
    {
        if ($payable->paymentAmountMinor() < 1) {
            throw new PaymentException('A payment attempt requires a positive amount.');
        }

        return DB::transaction(function () use ($payable, $provider, $idempotencyKey): Payment {
            $open = $this->attemptsFor($payable)->firstWhere(fn (Payment $p): bool => $p->status->isOpen());

            if ($open !== null) {
                throw new PaymentException('A payment attempt is already in progress for this item.');
            }

            $payment = Payment::query()->create([
                'payable_type' => $payable->paymentPayableType(),
                'payable_id' => $payable->paymentPayableId(),
                'user_id' => $payable->paymentUserId(),
                'provider' => $provider,
                'amount_minor' => $payable->paymentAmountMinor(),
                'currency_code' => $payable->paymentCurrencyCode(),
                'status' => PaymentStatus::Pending,
                'idempotency_key' => $idempotencyKey,
                'metadata' => $payable->paymentMetadata() ?: null,
            ]);

            $this->audit->logSystem(self::LOG_NAME, 'payment_attempt_started', sprintf('Payment attempt opened for %s.', $payable->paymentReference()), $payment, $this->metadata($payment));

            return $payment;
        });
    }

    /** @return Collection<int, Payment> every attempt for this payable, newest first */
    public function attemptsFor(Payable $payable): Collection
    {
        return Payment::query()
            ->forPayable($payable->paymentPayableType(), $payable->paymentPayableId())
            ->orderByDesc('created_at')
            ->get();
    }

    /** Whether any attempt for this payable has settled as Paid. */
    public function isPaid(Payable $payable): bool
    {
        return Payment::query()
            ->forPayable($payable->paymentPayableType(), $payable->paymentPayableId())
            ->paid()
            ->exists();
    }

    /**
     * Moves an attempt to a settled/in-flight state. Provider references
     * are recorded here rather than guessed later.
     *
     * @throws PaymentException on an illegal transition
     */
    public function transition(Payment $payment, PaymentStatus $to, array $attributes = []): Payment
    {
        return DB::transaction(function () use ($payment, $to, $attributes): Payment {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! $payment->status->canTransitionTo($to)) {
                throw new PaymentException(sprintf(
                    'A payment attempt cannot move from "%s" to "%s".',
                    $payment->status->value,
                    $to->value,
                ));
            }

            $payment->fill([
                ...$attributes,
                'status' => $to,
                'paid_at' => $to === PaymentStatus::Paid ? now() : $payment->paid_at,
                'failed_at' => $to === PaymentStatus::Failed ? now() : $payment->failed_at,
            ])->save();

            $this->audit->logSystem(self::LOG_NAME, 'payment_attempt_'.$to->value, sprintf('Payment attempt moved to %s.', $to->label()), $payment, $this->metadata($payment));

            return $payment->refresh();
        });
    }

    /** @return array<string, mixed> */
    private function metadata(Payment $payment): array
    {
        return [
            'payable_type' => $payment->payable_type,
            'payable_id' => $payment->payable_id,
            'provider' => $payment->provider,
            'amount_minor' => $payment->amount_minor,
            'currency_code' => $payment->currency_code,
            'status' => $payment->status->value,
        ];
    }
}
