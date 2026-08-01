<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use Livewire\Attributes\Url;

/**
 * URL-backed section selection for the report pages that host more than
 * one registered report definition.
 *
 * Three pages do:
 *   - Learning Analytics — `learning_progress`, `learning_plan_report`,
 *     `homework_report`
 *   - Wallet & Refunds — `wallet_activity`, `refund_report`
 *   - Referrals & Communications — `referral_activity`,
 *     `review_quality_analytics`, `notification_delivery`
 *
 * Without this, a dashboard card for "homework overdue" and one for
 * "plans review-due" would both land on the same URL at the top of a
 * long page, and the reader would have to hunt for the right block.
 *
 * A URL-backed property is used rather than a browser fragment because
 * Livewire re-renders replace the DOM, and a `#hash` set before
 * hydration does not survive that reliably. The property drives both a
 * visible highlight and a post-render scroll.
 *
 * The incoming value is validated against the page's own allow-list, so
 * `?section=<anything>` can never select something that does not exist —
 * it simply resolves to null and the page renders normally from the top.
 */
trait HasReportSectionState
{
    #[Url(as: 'section')]
    public ?string $section = null;

    /**
     * Section keys this page recognises, in display order.
     *
     * @return list<string>
     */
    abstract public function sectionKeys(): array;

    /** The requested section, or null when absent or unrecognised. */
    public function activeSection(): ?string
    {
        if (! filled($this->section)) {
            return null;
        }

        return in_array($this->section, $this->sectionKeys(), true) ? $this->section : null;
    }

    public function isActiveSection(string $key): bool
    {
        return $this->activeSection() === $key;
    }

    /**
     * Highlight classes for the active section, so a reader arriving
     * from a dashboard card can see which block they were sent to
     * rather than only being scrolled to it.
     */
    public function sectionHighlightClasses(string $key): string
    {
        return $this->isActiveSection($key)
            ? 'ring-2 ring-primary-500 dark:ring-primary-400'
            : '';
    }
}
