<?php

declare(strict_types=1);

namespace App\Services\Phone;

use App\Contracts\PhoneOtpSender;
use RuntimeException;

final class UnavailablePhoneOtpSender implements PhoneOtpSender
{
    public function available(): bool
    {
        return false;
    }

    public function send(string $e164, string $code): void
    {
        throw new RuntimeException('Phone verification is temporarily unavailable. Please try again later.');
    }
}
