<?php

declare(strict_types=1);

namespace App\PromotionalCredits\Enums;

/**
 * Campaign lifecycle (SRS §16.17-§16.19, mirrors ReferralCampaignStatus).
 * Transitions are owned exclusively by PromotionalCreditService —
 * Archived is terminal and a Completed campaign can never become
 * active again. This single status column also serves as the
 * "enabled/disabled state" requirement — Active is enabled, every
 * other status is effectively disabled for new issuance; a separate
 * boolean would only duplicate this same information.
 */
enum PromotionalCreditCampaignStatus: string
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

    public function isEditable(): bool
    {
        return match ($this) {
            self::Draft, self::Paused => true,
            self::Active, self::Completed, self::Archived => false,
        };
    }
}
