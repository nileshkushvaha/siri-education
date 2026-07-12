<?php

declare(strict_types=1);

namespace App\Earnings\Providers\RazorpayX\DTOs;

/**
 * Decrypted bank details exist only transiently inside this DTO's
 * construction and the client call it feeds — never logged, never
 * serialized into a queue payload, never persisted anywhere but the
 * (already-encrypted) InstructorPayoutMethod it was decrypted from.
 */
final readonly class RazorpayXFundAccountRequest
{
    public function __construct(
        public string $contactId,
        public string $accountHolderName,
        public string $accountNumber,
        public string $ifsc,
    ) {}
}
