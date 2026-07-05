<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Student-side lifecycle — separate from App\Models\User::status
 * (account/login eligibility) and App\Enums\InstructorStatus
 * (instructor application/professional lifecycle). Lives on
 * UserProfile::student_status, nullable — set to Registered when the
 * user is assigned the 'student' role at registration; null for users
 * who never held the student role.
 */
enum StudentStatus: string
{
    case Registered = 'registered';
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registered',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Registered => 'info',
            self::Active => 'success',
            self::Suspended => 'danger',
            self::Archived => 'gray',
        };
    }
}
