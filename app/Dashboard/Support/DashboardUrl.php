<?php

declare(strict_types=1);

namespace App\Dashboard\Support;

use App\Dashboard\DTOs\DashboardContext;
use App\Filament\Pages\BookingLessonMeetingOperations;
use App\Filament\Pages\InstructorFinancials;
use App\Filament\Pages\InstructorPerformance;
use App\Filament\Pages\LearningAnalytics;
use App\Filament\Pages\MarketplaceSupplyDemand;
use App\Filament\Pages\PaymentsReconciliation;
use App\Filament\Pages\RechargeMonitoring;
use App\Filament\Pages\ReferralCommunicationReports;
use App\Filament\Pages\ReportingHub;
use App\Filament\Pages\ReviewsQualityDashboard;
use App\Filament\Pages\StudentEngagement;
use App\Filament\Pages\WalletRefunds;

/**
 * Builds every dashboard destination.
 *
 * Two destination kinds exist and they behave differently, so both are
 * centralised here rather than assembled ad hoc in Blade:
 *
 *  - **Resource index pages** support Filament's own URL state
 *    (`Filament\Resources\Pages\ListRecords` declares
 *    `#[Url(as: 'filters')] $tableFilters` and `#[Url(as: 'tab')] $activeTab`),
 *    so a filtered index is `?filters[<name>][value]=<value>` and a tab
 *    is `?tab=<key>`. Filter names here are the literal
 *    `SelectFilter::make()` names from each resource's table class.
 *
 *  - **Report pages** carry the dashboard's period/country context
 *    through their own `#[Url]` bindings (see
 *    `App\Filament\Pages\Concerns\HasReportUrlState`). Only the
 *    parameters a given report actually declares support for are ever
 *    appended — a report never receives a filter it does not implement.
 *
 * No method here invents a route. Every destination corresponds to a
 * registered Filament page or resource index that exists today.
 */
final class DashboardUrl
{
    /** Query key used by multi-report pages to select a section. */
    public const string SECTION_PARAMETER = 'section';

    // ── Report pages ─────────────────────────────────────────────────

    /**
     * @param  array<string, string|int|null>  $extra  Report-specific filters; nulls are dropped.
     */
    public static function operations(DashboardContext $context, array $extra = []): string
    {
        return BookingLessonMeetingOperations::getUrl(self::merge($context, $extra));
    }

    /** @param array<string, string|int|null> $extra Report-specific filters; nulls are dropped. */
    public static function studentEngagement(DashboardContext $context, array $extra = []): string
    {
        return StudentEngagement::getUrl(self::merge($context, $extra));
    }

    /** @param array<string, string|int|null> $extra Report-specific filters; nulls are dropped. */
    public static function instructorPerformance(DashboardContext $context, array $extra = []): string
    {
        return InstructorPerformance::getUrl(self::merge($context, $extra));
    }

    /** @param array<string, string|int|null> $extra Report-specific filters; nulls are dropped. */
    public static function marketplace(DashboardContext $context, array $extra = []): string
    {
        return MarketplaceSupplyDemand::getUrl(self::merge($context, $extra));
    }

    /**
     * Learning Analytics hosts three registered report definitions
     * (`learning_progress`, `learning_plan_report`, `homework_report`),
     * so a dashboard card must be able to address the right one.
     */
    /** @param array<string, string|int|null> $extra */
    public static function learningAnalytics(DashboardContext $context, ?string $section = null, array $extra = []): string
    {
        // Learning Analytics does not declare Country support, so the
        // global country filter is deliberately not forwarded.
        $parameters = self::periodOnly($context);

        if ($section !== null) {
            $parameters[self::SECTION_PARAMETER] = $section;
        }

        return LearningAnalytics::getUrl([...$parameters, ...self::clean($extra)]);
    }

    public static function reviewsQuality(): string
    {
        // The moderation-queue owner is a current-state workload page
        // with no reporting period of its own — forwarding one would
        // imply a period scoping it does not have.
        return ReviewsQualityDashboard::getUrl();
    }

    /** Wallet & Refunds hosts both `wallet_activity` and `refund_report`. */
    public static function walletRefunds(DashboardContext $context, ?string $section = null): string
    {
        $parameters = self::periodOnly($context);

        if ($section !== null) {
            $parameters[self::SECTION_PARAMETER] = $section;
        }

        return WalletRefunds::getUrl($parameters);
    }

    /** Referrals & Communications hosts `referral_activity`, `review_quality_analytics` and `notification_delivery`. */
    public static function referralCommunication(DashboardContext $context, ?string $section = null): string
    {
        $parameters = self::periodOnly($context);

        if ($section !== null) {
            $parameters[self::SECTION_PARAMETER] = $section;
        }

        return ReferralCommunicationReports::getUrl($parameters);
    }

    public static function payments(DashboardContext $context): string
    {
        return PaymentsReconciliation::getUrl(self::periodOnly($context));
    }

    public static function instructorFinancials(DashboardContext $context): string
    {
        return InstructorFinancials::getUrl(self::periodOnly($context));
    }

    public static function rechargeMonitoring(): string
    {
        // Current-state operational health; not period-scoped.
        return RechargeMonitoring::getUrl();
    }

    public static function reportingHub(): string
    {
        return ReportingHub::getUrl();
    }

    // ── Resource indexes ─────────────────────────────────────────────

    /**
     * Builds `?filters[<name>][value]=<value>` for a plain
     * `SelectFilter`. Every filter name passed here is verified against
     * the resource's own table class — a name that does not exist would
     * be silently ignored by Filament, producing an unfiltered list that
     * looks correct but is not.
     *
     * @param  class-string  $resource
     * @param  array<string, string>  $filters
     */
    public static function resourceIndex(string $resource, array $filters = [], ?string $tab = null): string
    {
        $parameters = [];

        foreach ($filters as $name => $value) {
            $parameters['filters'][$name]['value'] = $value;
        }

        if ($tab !== null) {
            $parameters['tab'] = $tab;
        }

        return $resource::getUrl('index', $parameters);
    }

    // ── Internals ────────────────────────────────────────────────────

    /**
     * Period + country. Used by reports that declare Country support.
     *
     * @param  array<string, string|int|null>  $extra
     * @return array<string, string|int>
     */
    private static function merge(DashboardContext $context, array $extra): array
    {
        return [...$context->toQueryParameters(), ...self::clean($extra)];
    }

    /**
     * Period only — for reports that do not declare Country support.
     * Forwarding country to those would pretend a filter applied when
     * the report would ignore it.
     *
     * @return array<string, string|int>
     */
    private static function periodOnly(DashboardContext $context): array
    {
        $parameters = $context->toQueryParameters();
        unset($parameters['country']);

        return $parameters;
    }

    /**
     * @param  array<string, string|int|null>  $extra
     * @return array<string, string|int>
     */
    private static function clean(array $extra): array
    {
        return array_filter($extra, static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
