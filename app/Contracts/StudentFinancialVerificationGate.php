<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\BookingType;
use App\Models\User;

interface StudentFinancialVerificationGate
{
    public function assertEligible(User $student, BookingType $type): void;
}
