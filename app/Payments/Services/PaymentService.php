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

    /**
     * Resolves the attempt a verified provider event refers to.
     *
     * Order matters. Our own reference (carried as Razorpay `notes` /
     * Stripe `metadata` and stored as `idempotency_key`) is the most
     * specific signal, so it is tried first; the provider order id is
     * the fallback for events that lost the metadata. Both lookups are
     * scoped by provider, matching the `(provider, …)` unique indexes —
     * a Razorpay id can never resolve a Stripe attempt.
     *
     * Returns null rather than throwing: an unknown reference is a
     * normal webhook outcome (another environment's traffic, a
     * different domain's payment), never an error condition.
     */
    public function findByProviderReference(
        string $provider,
        ?string $reference,
        ?string $providerOrderId,
        ?string $providerPaymentId = null,
    ): ?Payment {
        if ($reference !== null) {
            $byReference = Payment::query()
                ->where('provider', $provider)
                ->where('idempotency_key', $reference)
                ->first();

            if ($byReference !== null) {
                return $byReference;
            }
        }

        foreach ([['provider_order_id', $providerOrderId], ['provider_payment_id', $providerPaymentId]] as [$column, $value]) {
            if ($value === null) {
                continue;
            }

            $match = Payment::query()->where('provider', $provider)->where($column, $value)->first();

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /** Records that the provider was polled about this attempt, whatever the answer was. */
    public function markSynced(Payment $payment): Payment
    {
        $payment->forceFill(['last_synced_at' => now()])->save();

        return $payment;
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
     * Records the provider-side order/intent id on an attempt that is
     * still Pending — the one write that is NOT a status change.
     *
     * Creating a gateway order does not advance the attempt's lifecycle
     * (the student has not paid anything yet; the provider has merely
     * been told what to collect), so forcing it through transition()
     * would mean inventing a fake Pending -> Pending edge.
     *
     * @throws PaymentException when the attempt has already settled, or already carries a different order id
     */
    public function recordProviderOrder(Payment $payment, string $providerOrderId): Payment
    {
        return DB::transaction(function () use ($payment, $providerOrderId): Payment {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status->isTerminal()) {
                throw new PaymentException('A settled payment attempt cannot be given a new provider order.');
            }

            if ($payment->provider_order_id !== null && $payment->provider_order_id !== $providerOrderId) {
                throw new PaymentException('This payment attempt already points at a different provider order.');
            }

            $payment->fill(['provider_order_id' => $providerOrderId])->save();

            return $payment->refresh();
        });
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
