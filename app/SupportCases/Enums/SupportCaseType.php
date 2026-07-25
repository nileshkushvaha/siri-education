<?php

declare(strict_types=1);

namespace App\SupportCases\Enums;

/**
 * SRS §25.6. `ComplianceCase` is explicitly "Future" in the SRS and is
 * intentionally not implemented in this phase.
 */
enum SupportCaseType: string
{
    case Student = 'student';
    case Instructor = 'instructor';
    case AdminOperational = 'admin_operational';

    public function label(): string
    {
        return match ($this) {
            self::Student => 'Student Support Case',
            self::Instructor => 'Instructor Support Case',
            self::AdminOperational => 'Admin Operational Case',
        };
    }
}
