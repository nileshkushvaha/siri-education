<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PhoneNumberData
{
    public function __construct(
        public string $countryIso2,
        public string $dialCode,
        public string $nationalNumber,
        public string $e164,
    ) {}
}
