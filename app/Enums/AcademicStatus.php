<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Shared status for academic master data (Subject, AcademicLevel).
 * Archived is deliberately distinct from Inactive: an archived entity
 * stays attached to its historical records (past bookings, homework,
 * teacher assignments) but must never appear in a picker for new
 * assignments — see scopeActive()/scopeAvailableForAssignment() on
 * each model.
 */
enum AcademicStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'warning',
            self::Archived => 'gray',
        };
    }
}
