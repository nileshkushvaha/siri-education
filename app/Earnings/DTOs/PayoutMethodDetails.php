<?php

declare(strict_types=1);

namespace App\Earnings\DTOs;

/**
 * Sensitive bank-transfer details in transit between a validated form
 * and the service layer. Instances are short-lived and must never be
 * logged, serialized into Livewire snapshots, or echoed back to the
 * browser. Normalization (for fingerprinting) lives here so every
 * caller produces identical identifying strings.
 */
final readonly class PayoutMethodDetails
{
    public function __construct(
        public string $accountHolderName,
        public ?string $bankName = null,
        public ?string $accountNumber = null,
        public ?string $iban = null,
        public ?string $routingType = null,
        public ?string $routingNumber = null,
        public ?string $swiftBic = null,
        public ?string $branchName = null,
        public ?string $accountType = null,
        public ?string $beneficiaryAddress = null,
    ) {}

    /** Uppercased, stripped of spaces/dashes — the canonical form for matching. */
    public static function normalizeIdentifier(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper((string) preg_replace('/[\s\-]+/', '', $value));

        return $normalized === '' ? null : $normalized;
    }

    /** The primary identifying value: account number, falling back to IBAN. */
    public function primaryIdentifier(): ?string
    {
        return self::normalizeIdentifier($this->accountNumber)
            ?? self::normalizeIdentifier($this->iban);
    }

    /** Last 4 characters of the primary identifier — safe to display. */
    public function lastFour(): string
    {
        return substr($this->primaryIdentifier() ?? '', -4);
    }

    /** @return array<string, ?string> The payload stored under the encrypted cast. */
    public function toArray(): array
    {
        return [
            'account_holder_name' => $this->accountHolderName,
            'bank_name' => $this->bankName,
            'account_number' => self::normalizeIdentifier($this->accountNumber),
            'iban' => self::normalizeIdentifier($this->iban),
            'routing_type' => $this->routingType,
            'routing_number' => self::normalizeIdentifier($this->routingNumber),
            'swift_bic' => self::normalizeIdentifier($this->swiftBic),
            'branch_name' => $this->branchName,
            'account_type' => $this->accountType,
            'beneficiary_address' => $this->beneficiaryAddress,
        ];
    }
}
