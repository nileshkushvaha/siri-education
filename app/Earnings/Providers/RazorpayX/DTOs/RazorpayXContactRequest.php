<?php

declare(strict_types=1);

namespace App\Earnings\Providers\RazorpayX\DTOs;

/** No personal data beyond what RazorpayX's Contact API itself requires; never a raw Eloquent model. */
final readonly class RazorpayXContactRequest
{
    public function __construct(
        public string $name,
        public string $referenceId,
        public string $type = 'vendor',
        public ?string $email = null,
        public ?string $contact = null,
    ) {}
}
