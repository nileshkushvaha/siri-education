<?php

declare(strict_types=1);

namespace App\Curriculum\Enums;

/**
 * Curriculum version lifecycle (SRS Book 2 §4.9/§4.21#5, §5.8 — a
 * curriculum "version" is what gets drafted/published/archived/
 * retired, not the parent Curriculum identity row). Mirrors
 * PromotionalCreditCampaignStatus's shape: transitions are owned
 * exclusively by CurriculumService, forward-only, no reverse edges —
 * historical meaning is never rewritten once Published.
 */
enum CurriculumVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Archived => 'Archived',
            self::Retired => 'Retired',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Published => 'success',
            self::Archived => 'warning',
            self::Retired => 'danger',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Published],
            self::Published => [self::Archived],
            self::Archived => [self::Retired],
            self::Retired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Only a Draft's structure (modules/topics/notes) may still be mutated. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
