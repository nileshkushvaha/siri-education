<?php

declare(strict_types=1);

namespace App\Services\Student;

use App\Booking\Exceptions\BookingException;
use App\Contracts\StudentFinancialVerificationGate;
use App\Models\BookingType;
use App\Models\User;

final class DefaultStudentFinancialVerificationGate implements StudentFinancialVerificationGate
{
    public function assertEligible(User $student, BookingType $type): void
    {
        if (! $type->is_paid || ! $student->hasRole('student')) {
            return;
        }
        if (blank($student->profile?->phone_e164)) {
            throw new BookingException('Add a mobile number to your profile before booking a paid lesson.');
        }
        if ($student->profile->phone_verified_at === null) {
            throw new BookingException('Verify your mobile number before booking a paid lesson.');
        }
    }
}
