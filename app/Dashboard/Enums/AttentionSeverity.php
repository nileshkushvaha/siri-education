<?php

declare(strict_types=1);

namespace App\Dashboard\Enums;

/**
 * Severity ranking for a Needs Attention card. Deliberately distinct
 * from `App\Alerts\Enums\OperationalAlertSeverity` and
 * `App\Quality\Enums\InstructorQualityAlertSeverity`: those describe a
 * single stored domain row, while this describes how urgently a
 * *category* of work should be surfaced on the dashboard. A card is
 * mapped onto this scale by the attention feed, never read from a
 * domain column directly.
 *
 * `rank()` is the primary ordering key (lower sorts first). Within one
 * severity the feed applies its own secondary weight so financial
 * integrity and imminent lesson-access problems precede ordinary
 * workload queues.
 */
enum AttentionSeverity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Warning = 'warning';
    case Info = 'info';
    case Success = 'success';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::High => 'High',
            self::Warning => 'Warning',
            self::Info => 'Informational',
            self::Success => 'Healthy',
        };
    }

    /** Lower sorts first. Success is last — an integrity confirmation, never a task. */
    public function rank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::High => 1,
            self::Warning => 2,
            self::Info => 3,
            self::Success => 4,
        };
    }

    /**
     * A Heroicon name. Severity is never conveyed by colour alone —
     * every card renders this icon plus the textual `label()`.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Critical => 'heroicon-o-exclamation-triangle',
            self::High => 'heroicon-o-exclamation-circle',
            self::Warning => 'heroicon-o-bell-alert',
            self::Info => 'heroicon-o-information-circle',
            self::Success => 'heroicon-o-check-circle',
        };
    }

    /**
     * Tailwind utility classes for the card surface. Kept here rather
     * than in Blade so the light/dark pairing is defined exactly once.
     */
    public function surfaceClasses(): string
    {
        return match ($this) {
            self::Critical => 'bg-danger-50 ring-danger-600/20 hover:bg-danger-100 dark:bg-danger-500/10 dark:ring-danger-400/30 dark:hover:bg-danger-500/20',
            self::High => 'bg-danger-50/60 ring-danger-600/15 hover:bg-danger-100/70 dark:bg-danger-500/5 dark:ring-danger-400/20 dark:hover:bg-danger-500/15',
            self::Warning => 'bg-warning-50 ring-warning-600/20 hover:bg-warning-100 dark:bg-warning-500/10 dark:ring-warning-400/30 dark:hover:bg-warning-500/20',
            self::Info => 'bg-info-50 ring-info-600/20 hover:bg-info-100 dark:bg-info-500/10 dark:ring-info-400/30 dark:hover:bg-info-500/20',
            self::Success => 'bg-success-50 ring-success-600/20 hover:bg-success-100 dark:bg-success-500/10 dark:ring-success-400/30 dark:hover:bg-success-500/20',
        };
    }

    /** Text/icon colour paired with {@see surfaceClasses()}. */
    public function accentClasses(): string
    {
        return match ($this) {
            self::Critical, self::High => 'text-danger-700 dark:text-danger-400',
            self::Warning => 'text-warning-700 dark:text-warning-400',
            self::Info => 'text-info-700 dark:text-info-400',
            self::Success => 'text-success-700 dark:text-success-400',
        };
    }
}
