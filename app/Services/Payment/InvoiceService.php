<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Models\BookingPayment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\StudentPackagePurchase;
use App\Models\WalletRecharge;
use App\Payments\Enums\PaymentStatus;
use App\Services\AuditTrailService;
use App\Settings\GeneralSettings;
use App\Wallet\Enums\WalletRechargeStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single authoritative writer of `invoices` (SRS §14.21-14.24).
 * Controllers, Livewire components, Filament resources, and
 * jobs must never create an invoice directly — only the generation
 * listeners call this service, and only in reaction to an
 * authoritative success event (booking payment settled, wallet
 * recharge succeeded, package purchase settled).
 *
 * Idempotent by construction: a redelivered event (or a genuine
 * concurrent race) never produces a second invoice for the same
 * source — checked first, then re-checked against the unique
 * (source_type, source_id) constraint if a race is lost.
 */
final class InvoiceService
{
    public function __construct(
        private readonly InvoiceNumberAllocator $numbers,
        private readonly AuditTrailService $audit,
    ) {}

    public function generateForBookingPayment(BookingPayment $payment): Invoice
    {
        if ($payment->status !== BookingPaymentRecordStatus::Captured) {
            throw new RuntimeException(sprintf(
                'Booking payment %s has not captured; refusing to generate an invoice.',
                $payment->id,
            ));
        }

        $existing = $this->existingInvoice(BookingPayment::class, (string) $payment->id);
        if ($existing !== null) {
            return $existing;
        }

        $booking = $payment->booking;
        $user = $payment->user;
        $general = app(GeneralSettings::class);

        return $this->createOnce(BookingPayment::class, (string) $payment->id, [
            'user_id' => $payment->user_id,
            'student_name' => $user?->name ?? 'Unknown student',
            'billing_country' => $user?->profile?->country?->name,
            'amount_minor' => $payment->amount_minor,
            'currency_code' => $payment->currency_code,
            'payment_date' => $payment->paid_at ?? now(),
            'payment_reference' => (string) $payment->idempotency_key,
            'service_description' => $booking !== null
                ? sprintf('Payment for booking %s (%s)', $booking->reference, $booking->type?->name ?? 'Session')
                : 'Booking payment',
            'booking_reference' => $booking?->reference,
            'wallet_recharge_reference' => null,
            'package_purchase_reference' => null,
            'organization_name' => $general->organization_name,
            'organization_address' => $general->address,
            'organization_support_email' => $general->support_email,
            'organization_support_phone' => $general->support_phone,
            'organization_website_url' => $general->website_url,
            'issued_at' => now(),
        ]);
    }

    public function generateForWalletRecharge(WalletRecharge $recharge): Invoice
    {
        if ($recharge->status !== WalletRechargeStatus::Succeeded) {
            throw new RuntimeException(sprintf(
                'Wallet recharge %s has not succeeded; refusing to generate an invoice.',
                $recharge->id,
            ));
        }

        $existing = $this->existingInvoice(WalletRecharge::class, (string) $recharge->id);
        if ($existing !== null) {
            return $existing;
        }

        $user = $recharge->user;
        $general = app(GeneralSettings::class);

        return $this->createOnce(WalletRecharge::class, (string) $recharge->id, [
            'user_id' => $recharge->user_id,
            'student_name' => $user?->name ?? 'Unknown student',
            'billing_country' => $user?->profile?->country?->name,
            'amount_minor' => $recharge->amount_minor,
            'currency_code' => $recharge->currency_code,
            'payment_date' => $recharge->succeeded_at ?? now(),
            'payment_reference' => $recharge->idempotency_key,
            'service_description' => 'Wallet recharge',
            'booking_reference' => null,
            'wallet_recharge_reference' => $recharge->idempotency_key,
            'package_purchase_reference' => null,
            'organization_name' => $general->organization_name,
            'organization_address' => $general->address,
            'organization_support_email' => $general->support_email,
            'organization_support_phone' => $general->support_phone,
            'organization_website_url' => $general->website_url,
            'issued_at' => now(),
        ]);
    }

    /**
     * The package receipt (SRS §14.21 — "successful payments", of which
     * a package purchase is now one).
     *
     * Keyed on the Payment, not the purchase, for the same reason the
     * booking receipt is keyed on the BookingPayment: one purchase may
     * accumulate many attempts (the aggregate stays PendingPayment
     * across declines), and the receipt documents the attempt that
     * actually collected money.
     *
     * Every financial field is copied from the settled attempt and the
     * immutable purchase snapshot — never from the proposal's current
     * price or a live pricing call — so a later price-matrix edit
     * cannot rewrite what a student already paid.
     */
    public function generateForPackagePurchase(Payment $payment): Invoice
    {
        if ($payment->status !== PaymentStatus::Paid) {
            throw new RuntimeException(sprintf(
                'Payment %s has not settled; refusing to generate a package receipt.',
                $payment->id,
            ));
        }

        $existing = $this->existingInvoice(StudentPackagePurchase::class, (string) $payment->id);
        if ($existing !== null) {
            return $existing;
        }

        $purchase = $payment->payable;

        if (! $purchase instanceof StudentPackagePurchase) {
            throw new RuntimeException(sprintf(
                'Payment %s does not belong to a package purchase.',
                $payment->id,
            ));
        }

        $user = $payment->user;
        $general = app(GeneralSettings::class);
        $proposal = $purchase->proposal;

        return $this->createOnce(StudentPackagePurchase::class, (string) $payment->id, [
            'user_id' => $payment->user_id,
            'student_name' => $user?->name ?? 'Unknown student',
            'billing_country' => $user?->profile?->country?->name,
            // The purchase's own frozen commercial snapshot.
            'amount_minor' => $purchase->amount_minor,
            'currency_code' => $purchase->currency_code,
            'payment_date' => $payment->paid_at ?? now(),
            'payment_reference' => (string) $purchase->reference,
            'service_description' => $proposal !== null
                ? sprintf(
                    'Lesson package: %d lessons (%d paid + %d bonus)%s',
                    $proposal->total_quantity,
                    $proposal->paid_quantity,
                    $proposal->bonus_quantity,
                    $proposal->subject?->name !== null ? ' — '.$proposal->subject->name : '',
                )
                : 'Lesson package purchase',
            'booking_reference' => null,
            'wallet_recharge_reference' => null,
            'package_purchase_reference' => $purchase->reference,
            'organization_name' => $general->organization_name,
            'organization_address' => $general->address,
            'organization_support_email' => $general->support_email,
            'organization_support_phone' => $general->support_phone,
            'organization_website_url' => $general->website_url,
            'issued_at' => now(),
        ]);
    }

    private function existingInvoice(string $sourceType, string $sourceId): ?Invoice
    {
        return Invoice::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createOnce(string $sourceType, string $sourceId, array $attributes): Invoice
    {
        try {
            return DB::transaction(function () use ($sourceType, $sourceId, $attributes): Invoice {
                $invoice = Invoice::query()->create([
                    ...$attributes,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'invoice_number' => $this->numbers->allocate(),
                ]);

                $this->audit->logSystem(
                    'invoices',
                    'invoice_generated',
                    sprintf('Invoice %s generated.', $invoice->invoice_number),
                    $invoice,
                    ['source_type' => $sourceType, 'source_id' => $sourceId],
                );

                return $invoice;
            });
        } catch (QueryException $e) {
            // A genuine concurrent race lost to a second generation
            // attempt for the same source — the unique (source_type,
            // source_id) constraint is the final defense described in
            // this service's own docblock. Return the winner's row
            // rather than surfacing a raw database error.
            $existing = $this->existingInvoice($sourceType, $sourceId);

            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        }
    }
}
