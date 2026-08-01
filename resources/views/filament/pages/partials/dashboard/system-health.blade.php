{{--
    Super-administrator system health.

    Renders only when the viewer holds at least one system permission —
    a manager, who is not seeded with queue/scheduler/cache permissions,
    never sees this and never triggers its queries.

    Deliberately last and visually subordinate: these are not education
    administration tasks. It must still be present, because thirteen of
    the scheduled commands are financial or lesson-lifecycle critical, so
    an unnoticed stalled queue silently breaks money movement.

    Status wording distinguishes three genuinely different situations —
    a provider deliberately switched off, one enabled but with
    unverified credentials, and an actual operational failure. "Not
    activated" is never dressed as a failure.

    Provider activation appears here as an operational fact. No
    credential, key fragment or webhook secret is read or shown.
--}}
@if ($dashboard->systemHealth !== null && $dashboard->systemHealth->hasAnything())
    @php $health = $dashboard->systemHealth; @endphp

    <section aria-labelledby="dashboard-system-heading" class="border-t border-gray-950/5 pt-5 dark:border-white/10">
        <h2 id="dashboard-system-heading" class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            System health
        </h2>

        <div class="mt-2.5 grid grid-cols-1 gap-2.5 sm:grid-cols-2 xl:grid-cols-4">

            @if ($health->failedJobCount !== null)
                @php $jobsHealthy = $health->failedJobCount === 0; @endphp
                <div class="dash-d-ops dash-card gap-2 p-3">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Failed jobs</p>
                        <span class="dash-pill {{ $jobsHealthy ? 'dash-pill-healthy' : 'dash-pill-critical' }}">
                            {{ $jobsHealthy ? 'Healthy' : 'Critical' }}
                        </span>
                    </div>
                    <p @class([
                        'text-xl font-bold leading-none tabular-nums',
                        'text-gray-950 dark:text-white' => $jobsHealthy,
                        'text-danger-600 dark:text-danger-400' => ! $jobsHealthy,
                    ])>
                        {{ number_format($health->failedJobCount) }}
                    </p>
                    @if ($health->criticalJobAlertCount !== null)
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">
                            {{ number_format($health->criticalJobAlertCount) }} open critical-job alert{{ $health->criticalJobAlertCount === 1 ? '' : 's' }}
                        </p>
                    @endif
                </div>
            @endif

            @if ($health->schedulerLastRunLabel !== null)
                <div class="dash-d-ops dash-card gap-2 p-3">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Scheduler</p>
                        <span class="dash-pill {{ $health->schedulerHealthy ? 'dash-pill-healthy' : 'dash-pill-warning' }}">
                            {{ $health->schedulerHealthy ? 'Healthy' : 'Check required' }}
                        </span>
                    </div>
                    <p class="text-sm font-semibold text-gray-950 dark:text-white">
                        {{ $health->schedulerHealthy ? 'Running' : 'Stalled or failing' }}
                    </p>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400">Last run {{ $health->schedulerLastRunLabel }}</p>
                </div>
            @endif

            @foreach ($health->providers as $provider)
                @php
                    // Three distinct states, never collapsed into "error":
                    //   off        — deliberately switched off in settings
                    //   unverified — on, but credentials do not validate
                    //   live/test  — activated
                    [$pillClass, $pillLabel] = match (true) {
                        $provider->isActivated() => ['dash-pill-healthy', $provider->statusLabel],
                        $provider->enabled => ['dash-pill-warning', 'Configuration issue'],
                        default => ['dash-pill-idle', 'Not activated'],
                    };
                @endphp

                <div class="dash-d-finance dash-card gap-2 p-3">
                    <div class="flex items-start justify-between gap-2">
                        <p class="min-w-0 break-words text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ $provider->label }}
                        </p>
                        <span class="dash-pill {{ $pillClass }} shrink-0">{{ $pillLabel }}</span>
                    </div>
                    <p class="flex items-center gap-1.5 text-sm font-semibold text-gray-950 dark:text-white">
                        <x-filament::icon
                            :icon="$provider->isActivated() ? 'heroicon-m-check-circle' : ($provider->enabled ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-pause-circle')"
                            @class([
                                'h-4 w-4 shrink-0',
                                'text-success-600 dark:text-success-400' => $provider->isActivated(),
                                'text-warning-600 dark:text-warning-400' => ! $provider->isActivated() && $provider->enabled,
                                'text-gray-400 dark:text-gray-500' => ! $provider->enabled,
                            ])
                        />
                        {{ $provider->statusLabel }}
                    </p>
                    <p class="break-words text-[11px] leading-snug text-gray-500 dark:text-gray-400">{{ $provider->detail }}</p>
                    @if ($provider->settingsUrl !== null)
                        <a
                            href="{{ $provider->settingsUrl }}"
                            class="group mt-auto inline-flex items-center gap-1 rounded text-[11px] font-semibold text-gray-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-gray-300"
                        >
                            Configure
                            <x-filament::icon icon="heroicon-m-arrow-right" class="dash-go h-3 w-3 shrink-0" />
                        </a>
                    @endif
                </div>
            @endforeach
        </div>

        @if (count($health->links) > 0)
            <div class="mt-2.5 flex flex-wrap gap-2">
                @foreach ($health->links as $link)
                    <a
                        href="{{ $link['url'] }}"
                        title="{{ $link['description'] }}"
                        class="group inline-flex min-h-9 items-center gap-2 rounded-lg border border-gray-950/10 px-3 py-2 text-xs font-medium text-gray-600 transition-colors hover:border-gray-950/20 hover:bg-gray-950/5 hover:text-gray-950 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 motion-reduce:transition-none dark:border-white/10 dark:text-gray-400 dark:hover:border-white/20 dark:hover:bg-white/5 dark:hover:text-white"
                    >
                        <x-filament::icon :icon="$link['icon']" class="h-4 w-4 shrink-0 opacity-70 transition-opacity group-hover:opacity-100 motion-reduce:transition-none" />
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </div>
        @endif
    </section>
@endif
