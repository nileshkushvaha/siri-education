<?php

declare(strict_types=1);

namespace App\Reporting\Enums;

/**
 * Reporting data-classification model (SRS §14). Deliberately
 * small — five fixed levels, not a generic configurable privacy engine.
 * Used to decide whether a field/value may appear in an aggregate
 * report, and to whom.
 */
enum ReportDataClassification: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Sensitive = 'sensitive';
    case Financial = 'financial';
    case HighlyRestricted = 'highly_restricted';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Public',
            self::Internal => 'Internal',
            self::Sensitive => 'Sensitive',
            self::Financial => 'Financial',
            self::HighlyRestricted => 'Highly restricted',
        };
    }
}
