<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;

interface PhoneVerificationServiceInterface
{
    public function send(User $user, string $ip): void;

    public function verify(User $user, string $code, string $ip): void;

    public function invalidate(User $user): void;
}
