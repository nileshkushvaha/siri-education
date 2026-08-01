<?php

declare(strict_types=1);

namespace App\Dashboard\Services;

use App\Alerts\Enums\OperationalAlertStatus;
use App\Alerts\Enums\OperationalAlertType;
use App\Dashboard\DTOs\SystemHealthData;
use App\Filament\Pages\CacheManagerPage;
use App\Filament\Pages\QueueMonitorPage;
use App\Filament\Pages\SchedulerMonitorPage;
use App\Models\FailedJob;
use App\Models\OperationalAlert;
use App\Models\SchedulerHistory;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Builds the visually subordinate super-administrator system strip.
 *
 * Every field is gated on its own specific system permission and is
 * populated only after that check passes, so a manager — who is not
 * seeded with `queue_monitor.view`, `scheduler_monitor.view` or
 * `cache_manager.view` — never causes any of these queries to run.
 *
 * The strip exists because thirteen of the scheduled commands are
 * financial or lesson-lifecycle critical (earnings release, lesson
 * finalization, refunds, reconciliation), so an unnoticed stalled queue
 * silently breaks money movement. It must be visible — but never
 * prominent, and never in the primary area, because these are not
 * education-administration tasks.
 */
final readonly class SystemHealthReader
{
    public function __construct(
        private DashboardPermissions $permissions,
        private ProviderActivationReader $providers,
    ) {}

    public function read(User $user): SystemHealthData
    {
        $canQueue = $this->permissions->canViewQueueMonitor($user);
        $canScheduler = $this->permissions->canViewSchedulerMonitor($user);

        $schedulerRun = $canScheduler ? $this->latestSchedulerRun() : null;

        return new SystemHealthData(
            failedJobCount: $canQueue ? FailedJob::query()->count() : null,
            criticalJobAlertCount: $canQueue ? $this->criticalJobAlertCount() : null,
            schedulerLastRunLabel: $schedulerRun?->label,
            schedulerHealthy: $schedulerRun?->healthy,
            // Provider activation is a finance-operations fact, not a
            // credential — safe to show alongside system health, and the
            // reader never exposes keys or secrets.
            providers: $this->providers->all($user),
            links: $this->links($user),
        );
    }

    /**
     * Failed jobs the alert layer already classified as critical
     * (`CriticalJobClassifier` feeds `OperationalAlertType::CriticalFailedJob`).
     * Reused rather than reclassified here.
     */
    private function criticalJobAlertCount(): int
    {
        return OperationalAlert::query()
            ->where('status', OperationalAlertStatus::Open->value)
            ->where('type', OperationalAlertType::CriticalFailedJob->value)
            ->count();
    }

    private function latestSchedulerRun(): ?object
    {
        /** @var SchedulerHistory|null $latest */
        $latest = SchedulerHistory::query()->latest('ran_at')->first();

        if ($latest === null) {
            return null;
        }

        // `ran_at` is cast to `datetime`, which yields a mutable Carbon
        // unless the app opts into immutable dates — normalise either.
        $ranAt = CarbonImmutable::parse($latest->ran_at);

        return (object) [
            'label' => $ranAt->diffForHumans(),
            // A run older than an hour is suspicious: the busiest
            // scheduled commands run every five minutes.
            'healthy' => $latest->status !== 'failed' && $ranAt->greaterThan(CarbonImmutable::now()->subHour()),
        ];
    }

    /**
     * @return list<array{label: string, url: string, icon: string, description: string}>
     */
    private function links(User $user): array
    {
        $links = [];

        if ($this->permissions->canViewQueueMonitor($user)) {
            $links[] = [
                'label' => 'Queue Monitor',
                'url' => QueueMonitorPage::getUrl(),
                'icon' => 'heroicon-o-queue-list',
                'description' => 'Failed jobs and retries',
            ];
        }

        if ($this->permissions->canViewSchedulerMonitor($user)) {
            $links[] = [
                'label' => 'Scheduler Monitor',
                'url' => SchedulerMonitorPage::getUrl(),
                'icon' => 'heroicon-o-clock',
                'description' => 'Scheduled command history',
            ];
        }

        if ($this->permissions->canViewCacheManager($user)) {
            $links[] = [
                'label' => 'Cache Manager',
                'url' => CacheManagerPage::getUrl(),
                'icon' => 'heroicon-o-circle-stack',
                'description' => 'Clear and optimise caches',
            ];
        }

        return $links;
    }
}
