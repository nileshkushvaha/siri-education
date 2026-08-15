<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Support\AdminDayRange;
use App\Models\LoginHistory;
use App\Models\User;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Support\LocalDaySql;
use App\Reporting\ValueObjects\ReportingPeriod;
use App\Support\Timezone\LocalDay;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class StatsOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    // Gate::before() handles the super_admin bypass automatically,
    // so this single can() check covers both super_admin and manager.
    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('View:StatsOverviewWidget');
    }

    protected function getStats(): array
    {
        // TZ-5 (TZ-AUD-018): these are PLATFORM figures, not one admin's
        // worklist. "New this month" and "logins today" must read the
        // same for an admin in London and one in Los Angeles, so their
        // calendar comes from the configured reporting timezone rather
        // than from whoever happens to be signed in. (The operational
        // counterpart — "sessions on my schedule today" — is in
        // BookingStatsWidget and deliberately does the opposite.)
        //
        // MONTH()/YEAR()/DATE()/whereDate() all evaluated the UTC
        // calendar, which was neither the reporting day nor anyone's.
        $reportingTimezone = AdminDayRange::reportingLabel();
        $month = ReportingPeriod::forPreset(ReportingPeriodPreset::ThisMonth, $reportingTimezone);
        $today = AdminDayRange::reportingToday();
        $chartStart = LocalDay::containing(CarbonImmutable::now('UTC'), $reportingTimezone)->startUtc->subDays(6);

        // Single aggregated query replaces four separate count() calls
        $userStats = User::selectRaw(
            'COUNT(*) as total,
             SUM(status = ?) as active,
             SUM(status IN (?,?)) as blocked,
             SUM(created_at >= ? AND created_at < ?) as new_this_month',
            [User::STATUS_ACTIVE, User::STATUS_BLOCKED, User::STATUS_SUSPENDED, $month->startUtc, $month->endUtcExclusive]
        )->first();

        // One date-range GROUP BY query replaces 7 individual whereDate() calls
        [$dayExpression, $dayBindings] = LocalDaySql::dateExpression('created_at', $month);

        $dailyCounts = User::selectRaw($dayExpression.' as day, COUNT(*) as cnt', $dayBindings)
            ->where('created_at', '>=', $chartStart)
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('cnt', 'day');

        $chart = collect(range(6, 0))
            ->map(fn (int $i): int => (int) ($dailyCounts[$today->startUtc->setTimezone($reportingTimezone)->subDays($i)->format('Y-m-d')] ?? 0))
            ->values()
            ->all();

        $totalRoles = Role::count();
        $totalPermissions = Permission::count();
        $todayLogins = LoginHistory::query()
            ->where('logged_in_at', '>=', $today->startUtc)
            ->where('logged_in_at', '<', $today->endUtcExclusive)
            ->count();

        return [
            Stat::make('Total Users', (int) ($userStats->total ?? 0))
                ->description('+'.((int) ($userStats->new_this_month ?? 0)).' this month ('.$reportingTimezone.' reporting day)')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success')
                ->icon('heroicon-o-users')
                ->chart($chart),

            Stat::make('Active Users', (int) ($userStats->active ?? 0))
                ->description(((int) ($userStats->blocked ?? 0)).' blocked / suspended')
                ->descriptionIcon('heroicon-m-shield-exclamation')
                ->color('info')
                ->icon('heroicon-o-user-circle'),

            Stat::make('Roles', $totalRoles)
                ->description("{$totalPermissions} permissions configured")
                ->descriptionIcon('heroicon-m-key')
                ->color('warning')
                ->icon('heroicon-o-shield-check'),

            Stat::make("Today's Logins", $todayLogins)
                ->description('Login activity today ('.$reportingTimezone.' reporting day)')
                ->descriptionIcon('heroicon-m-clock')
                ->color('primary')
                ->icon('heroicon-o-finger-print'),
        ];
    }
}
