<?php

declare(strict_types=1);

namespace App\Referral\Enums;

/**
 * Campaign lifecycle (SRS 16.23). Transitions are narrow and owned
 * exclusively by ReferralCampaignService — Archived is terminal and a
 * Completed campaign can never become active again.
 */
enum ReferralCampaignStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::Paused => 'warning',
            self::Completed => 'info',
            self::Archived => 'gray',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Archived],
            self::Active => [self::Paused, self::Completed],
            self::Paused => [self::Active, self::Completed, self::Archived],
            self::Completed => [self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Rule and terms edits are allowed only before/outside live operation. */
    public function isEditable(): bool
    {
        return match ($this) {
            self::Draft, self::Paused => true,
            self::Active, self::Completed, self::Archived => false,
        };
    }
}
