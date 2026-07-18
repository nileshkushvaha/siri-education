<?php

declare(strict_types=1);

namespace App\Contracts;

interface PhoneOtpSender
{
    public function available(): bool;

    public function send(string $e164, string $code): void;
}
