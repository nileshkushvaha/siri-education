<?php

declare(strict_types=1);

namespace Tests\Support\Payments;

use App\Payments\Contracts\Payable;

/**
 * Test-only Payable. Phase 4B.1 intentionally ships the generic payment
 * foundation BEFORE its first real consumer (StudentPackagePurchase,
 * Phase 4B.2), so the contract is exercised against this stand-in
 * rather than against a speculative production model.
 *
 * It is a plain object, not an Eloquent model: nothing in the generic
 * payment path requires a payable to be persisted, and proving that is
 * itself useful — `payments` deliberately has no foreign key to the
 * payable (see the create_payments_table migration).
 */
final class FakePayable implements Payable
{
    public function __construct(
        private readonly string $payableType = 'fake_payable',
        private readonly string $payableId = 'fake-payable-1',
        private readonly int $amountMinor = 28000,
        private readonly string $currencyCode = 'GBP',
        private readonly int $userId = 1,
        private readonly string $reference = 'FAKE-REF-1',
        private readonly array $metadata = [],
    ) {}

    public function paymentPayableType(): string
    {
        return $this->payableType;
    }

    public function paymentPayableId(): string
    {
        return $this->payableId;
    }

    public function paymentAmountMinor(): int
    {
        return $this->amountMinor;
    }

    public function paymentCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    public function paymentUserId(): int
    {
        return $this->userId;
    }

    public function paymentReference(): string
    {
        return $this->reference;
    }

    public function paymentMetadata(): array
    {
        return $this->metadata;
    }
}
